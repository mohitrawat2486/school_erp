<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\PromotionBatch;
use App\Models\Student;
use App\Models\User;
use App\Support\StudentDepartureRules;
use App\Support\StudentEnrollmentState;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class StudentDeparture
{
    private function hash(Student $student, array $input, ?UploadedFile $photo): string
    {
        $values = Arr::only($input, [...StudentService::FIELDS, 'guardians_present', 'guardians', 'primary_guardian', 'guardian_setup_revision', 'confirm_guardian_removals', 'enrollment', 'departure']);
        $normalize = function ($value) use (&$normalize) {
            if (is_array($value)) {
                ksort($value);

                return array_map($normalize, $value);
            }

            return is_bool($value) ? ($value ? '1' : '0') : (string) ($value ?? '');
        };

        return hash('sha256', json_encode([$student->id, $normalize($values), $photo ? hash_file('sha256', $photo->getRealPath()) : null]));
    }

    // StudentService already holds the school lock and transaction.
    public function prepare(User $actor, ?Student $student, array $input, ?UploadedFile $photo = null): ?array
    {
        $data = Validator::make($input, StudentDepartureRules::rules($input))->validate()['departure'] ?? [];
        if (! in_array($data['action'] ?? null, ['WITHDRAW', 'TRANSFER'], true)) {
            return null;
        }
        if (! $student) {
            throw ValidationException::withMessages(['departure' => 'Save the student with an active enrollment before recording a withdrawal or transfer.']);
        }
        Gate::forUser($actor->fresh())->authorize('update', $student);
        Gate::forUser($actor->fresh())->authorize('create', PromotionBatch::class);
        if (data_get($input, 'enrollment.action') === 'save') {
            throw ValidationException::withMessages(['departure' => 'Cancel pending enrollment changes before recording a withdrawal or transfer.']);
        }
        $hash = $this->hash($student, $input, $photo);
        $existing = PromotionBatch::where('operation_key', $data['operation_key'])->lockForUpdate()->first();
        if ($existing) {
            if ((int) $existing->actor_id !== (int) $actor->id || ! $existing->student_form_hash || ! hash_equals($existing->student_form_hash, $hash) || ! $existing->outcomes()->where('student_id', $student->id)->exists()) {
                throw ValidationException::withMessages(['departure.operation_key' => 'This departure confirmation has already been used. Reload the student before recording another action.']);
            }

            return ['replay' => true];
        }
        $year = AcademicYear::whereKey($data['academic_year_id'])->lockForUpdate()->firstOrFail();
        $enrollment = $student->enrollments()->where('academic_year_id', $year->id)->lockForUpdate()->first();
        $state = StudentEnrollmentState::data($year, $student);
        if (! hash_equals($state['revision'], $data['revision'])) {
            throw ValidationException::withMessages(['departure.revision' => 'Student or enrollment details changed. Latest enrollment details have been loaded. Review the departure again.']);
        }
        if (! $state['can_exit']) {
            throw ValidationException::withMessages(['departure' => $state['exit_reason']]);
        }
        Validator::make(['departure' => $data], ['departure.departure_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$enrollment->joined_at->format('Y-m-d'), 'before_or_equal:'.$year->end_date->format('Y-m-d'), 'before_or_equal:today']])->validate();

        return ['replay' => false, 'data' => $data, 'enrollment' => $enrollment, 'form_hash' => $hash];
    }

    public function apply(User $actor, ?array $prepared): void
    {
        if (! $prepared || $prepared['replay']) {
            return;
        }
        $source = $prepared['enrollment'];
        $data = $prepared['data'];
        try {
            $batch = app(PromotionService::class)->process($actor, [
                'operation_key' => $data['operation_key'], 'confirmed' => true, 'mode' => 'EXIT',
                'source_academic_year_id' => $source->academic_year_id, 'source_class_id' => $source->class_id, 'source_section_id' => $source->section_id,
                'rows' => [['source_enrollment_id' => $source->id, 'action' => $data['action'], 'notes' => $data['reason'], 'departure_date' => $data['departure_date'], 'destination_school' => $data['destination_school'] ?? null]],
            ]);
            $batch->student_form_hash = $prepared['form_hash'];
            $batch->save();
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['departure' => array_merge(...array_values($exception->errors()))]);
        }
    }
}
