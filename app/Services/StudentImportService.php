<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\User;
use App\Support\CurrentSchool;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StudentImportService
{
    public function authorize(User $actor): void
    {
        $actor = $actor->fresh();
        abort_unless($actor, 403);
        Gate::forUser($actor)->authorize('student.import');
        Gate::forUser($actor)->authorize('create', Student::class);
    }

    private function cacheKey(User $actor, string $token): string
    {
        return 'student-import:'.app(CurrentSchool::class)->id().':'.$actor->id.':'.$token;
    }

    private function fingerprint(array $row): string
    {
        return hash('sha256', json_encode([$row['data'], $row['enrollment']]));
    }

    public function review(User $actor, array $input): array
    {
        $this->authorize($actor);
        $report = app(StudentImportValidator::class)->validate($actor, $input);
        $token = (string) Str::uuid();
        $initial = array_map(fn ($row) => ['status' => $row['status'], 'fingerprint' => $this->fingerprint($row)], $report['rows']);
        Cache::put($this->cacheKey($actor, $token), Crypt::encryptString(json_encode(['input' => $input, 'initial' => $initial])), now()->addMinutes(30));
        $report['rows'] = array_map(fn ($row) => Arr::except($row, ['data', 'enrollment']), $report['rows']);

        return $report + ['review_token' => $token, 'chunk_size' => config('student-import.chunk_size'), 'expires_in' => 1800];
    }

    private function receipt(User $actor, string $token, int $chunk): ?array
    {
        return AuditLog::where('user_id', $actor->id)->where('action', 'STUDENT_IMPORT_CHUNK')->where('new_values->batch_id', $token)->where('new_values->chunk', $chunk)->first()?->new_values;
    }

    public function import(User $actor, string $token, int $chunk, bool $includeWarnings): array
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor, $token, $chunk, $includeWarnings) {
            School::whereKey(app(CurrentSchool::class)->id())->lockForUpdate()->firstOrFail();
            $this->authorize($actor);
            if ($receipt = $this->receipt($actor, $token, $chunk)) {
                abort_unless($receipt['include_warnings'] === $includeWarnings, 409, 'This batch was already confirmed with different warning settings.');

                return $receipt + ['replayed' => true];
            }
            $first = $this->receipt($actor, $token, 0);
            abort_if($first && $first['include_warnings'] !== $includeWarnings, 409, 'Keep the warning setting used when this import started.');
            abort_if($chunk > 0 && ! $this->receipt($actor, $token, $chunk - 1), 409, 'Import batches must be processed in order.');
            $cached = Cache::get($this->cacheKey($actor, $token));
            if (! $cached) {
                throw ValidationException::withMessages(['review_token' => 'This review expired. Validate the pasted data again before importing.']);
            }
            $review = json_decode(Crypt::decryptString($cached), true, flags: JSON_THROW_ON_ERROR);
            $size = config('student-import.chunk_size');
            $start = $chunk * $size;
            abort_if($start >= count($review['input']['rows']), 422, 'This batch is outside the reviewed data.');
            // Revalidate original mappings and data against the latest database state under the school lock.
            $fresh = app(StudentImportValidator::class)->validate($actor, $review['input']);
            $imported = [];
            $skipped = [];
            // Reserve every explicit roll first, so automatic allocation cannot consume a later pasted roll.
            $maxRolls = [];
            foreach ($fresh['rows'] as $row) {
                $placement = $row['enrollment'];
                if ($placement && $placement['roll_number'] && $row['status'] !== 'invalid') {
                    $key = $placement['academic_year_id'].':'.$placement['section_id'];
                    $maxRolls[$key] = max($maxRolls[$key] ?? 0, $placement['roll_number']);
                }
            }
            foreach (array_slice($fresh['rows'], $start, $size) as $row) {
                $initial = $review['initial'][$row['row'] - 1];
                $allowed = fn ($status) => $status === 'valid' || ($status === 'warning' && $includeWarnings);
                $same = hash_equals($initial['fingerprint'], $this->fingerprint($row));
                if (! $allowed($initial['status']) || ! $allowed($row['status']) || ! $same) {
                    $messages = $row['messages'];
                    foreach ($row['fields'] as $field => $cell) {
                        if ($cell['status'] === 'invalid') {
                            $messages[] = config('student-import.fields.'.$field.'.label', $field).': '.implode(' ', $cell['messages']);
                        }
                    }
                    if (! $same) {
                        $messages[] = 'Resolved data changed since review. Validate and review this row again.';
                    }
                    $skipped[] = ['row' => $row['row'], 'status' => $row['status'], 'messages' => $messages ?: ['Row was not included in the confirmed valid/warning selection.']];

                    continue;
                }
                try {
                    $student = app(StudentService::class)->save($actor, null, $row['data']);
                    if ($placement = $row['enrollment']) {
                        $key = $placement['academic_year_id'].':'.$placement['section_id'];
                        if ($placement['roll_number'] === null) {
                            $last = StudentEnrollment::where('academic_year_id', $placement['academic_year_id'])->where('section_id', $placement['section_id'])->max('roll_number');
                            $placement['roll_number'] = max((int) $last, $maxRolls[$key] ?? 0) + 1;
                            $maxRolls[$key] = $placement['roll_number'];
                        }
                        app(EnrollmentService::class)->save($actor, null, $placement + ['student_id' => $student->id]);
                    }
                } catch (ValidationException $exception) {
                    throw ValidationException::withMessages(['rows.'.$row['row'] => 'Row '.$row['row'].': '.implode(' ', Arr::flatten($exception->errors())).' This batch was rolled back.']);
                }
                $imported[] = ['row' => $row['row'], 'student_id' => $student->id];
            }
            $result = ['batch_id' => $token, 'chunk' => $chunk, 'include_warnings' => $includeWarnings, 'total' => count($fresh['rows']), 'imported' => count($imported), 'skipped' => count($skipped), 'failed' => 0, 'done' => $start + $size >= count($fresh['rows']), 'imported_rows' => $imported, 'skipped_rows' => $skipped];
            app(AuditLogger::class)->record($actor, 'STUDENT_IMPORT_CHUNK', 'student_import', $actor->id, [], $result, 'students');

            return $result + ['replayed' => false];
        });
    }
}
