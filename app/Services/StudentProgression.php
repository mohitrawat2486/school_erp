<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\PromotionBatch;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\StudentEnrollment;
use App\Models\User;
use App\Support\CurrentSchool;
use App\Support\StudentEnrollmentState;
use App\Support\StudentProgressionRules;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class StudentProgression
{
    private function authorize(User $actor): void
    {
        Gate::forUser($actor->fresh())->authorize('create', PromotionBatch::class);
    }

    public function catalog(): array
    {
        $classes = SchoolClass::with(['sections' => fn ($query) => $query->where(fn ($sections) => $sections->where('status', 'ACTIVE')->orWhereHas('enrollments', fn ($enrollments) => $enrollments->where('status', 'ACTIVE')))->orderBy('name')->orderBy('id')])->orderBy('sort_order')->orderBy('name')->orderBy('id')->get();

        return [
            'years' => AcademicYear::whereIn('status', ['ACTIVE', 'CLOSED'])->orderByDesc('start_date')->get()->map(fn ($year) => ['id' => $year->id, 'name' => $year->name, 'start' => $year->start_date->format('Y-m-d'), 'end' => $year->end_date->format('Y-m-d'), 'active' => $year->status->value === 'ACTIVE'])->all(),
            'classes' => $classes->map(fn ($class) => ['id' => $class->id, 'name' => $class->name, 'active' => $class->status->value === 'ACTIVE', 'sections' => $class->sections->map(fn ($section) => ['id' => $section->id, 'name' => $section->name, 'active' => $section->status->value === 'ACTIVE'])->all()])->all(),
        ];
    }

    private function source(array $data): array
    {
        $year = AcademicYear::whereIn('status', ['ACTIVE', 'CLOSED'])->findOrFail($data['source_academic_year_id']);
        $class = SchoolClass::findOrFail($data['source_class_id']);
        $section = Section::where('class_id', $class->id)->findOrFail($data['source_section_id']);

        return [$year, $class, $section];
    }

    private function rosterQuery(array $data)
    {
        return StudentEnrollment::where('academic_year_id', $data['source_academic_year_id'])->where('class_id', $data['source_class_id'])->where('section_id', $data['source_section_id']);
    }

    private function eligibleQuery(array $data)
    {
        $year = AcademicYear::findOrFail($data['source_academic_year_id']);

        return $this->rosterQuery($data)->where('status', 'ACTIVE')->whereDoesntHave('promotionOutcome')
            ->whereHas('student', fn ($students) => $students->where('status', 'ACTIVE')->whereDoesntHave('enrollments', fn ($enrollments) => $enrollments->whereHas('academicYear', fn ($years) => $years->where('start_date', '>', $year->start_date->format('Y-m-d')))));
    }

    public function roster(User $actor, array $data): array
    {
        $this->authorize($actor);
        [$year] = $this->source($data);
        $query = $this->rosterQuery($data)->with(['student', 'promotionOutcome.targetEnrollment.schoolClass', 'promotionOutcome.targetEnrollment.section']);
        if ($q = $data['q'] ?? null) {
            $query->whereHas('student', fn ($students) => $students->where(fn ($search) => $search->where('admission_number', 'like', '%'.$q.'%')->orWhereRaw("CONCAT_WS(' ',first_name,middle_name,last_name) LIKE ?", ['%'.$q.'%'])));
        }
        $records = $query->orderBy('roll_number')->orderBy('id')->paginate(20, ['*'], 'page', $data['page'] ?? 1);
        $eligible = $this->eligibleQuery($data)->whereIn('id', $records->pluck('id'))->pluck('id');

        return [
            'rows' => $records->map(function ($record) use ($eligible) {
                $outcome = $record->promotionOutcome;
                $reason = $outcome ? ucfirst(strtolower($outcome->action->value)).($outcome->targetEnrollment ? ' → '.$outcome->targetEnrollment->schoolClass->name.' / '.$outcome->targetEnrollment->section->name : '') : ($record->status->value !== 'ACTIVE' ? ucfirst(strtolower($record->status->value)).' enrollment' : ($record->student->status->value !== 'ACTIVE' ? 'Inactive student' : 'Newer enrollment exists'));

                return ['id' => $record->id, 'student_id' => $record->student_id, 'name' => $record->student->fullName(), 'admission_number' => $record->student->admission_number, 'roll_number' => $record->roll_number, 'eligible' => $eligible->contains($record->id), 'reason' => $eligible->contains($record->id) ? 'Ready for next year' : $reason];
            })->all(),
            'page' => $records->currentPage(), 'last_page' => $records->lastPage(), 'total' => $records->total(),
            'eligible_count' => $this->eligibleQuery($data)->count(), 'year_name' => $year->name,
        ];
    }

    private function prepare(array $command): array
    {
        [$year, $sourceClass, $sourceSection] = $this->source($command);
        $targetYear = AcademicYear::where('status', 'ACTIVE')->findOrFail($command['target_academic_year_id']);
        if ($targetYear->start_date->lte($year->end_date)) {
            throw ValidationException::withMessages(['target_academic_year_id' => 'Choose an active academic year starting after the source year ends.']);
        }
        $defaultClass = SchoolClass::active()->findOrFail($command['target_class_id']);
        $defaultSection = Section::eligible()->where('class_id', $defaultClass->id)->findOrFail($command['target_section_id']);
        Validator::make($command, ['joined_at' => ['date_format:Y-m-d', 'after_or_equal:'.$targetYear->start_date->format('Y-m-d'), 'before_or_equal:'.$targetYear->end_date->format('Y-m-d')]])->validate();
        $commands = collect($command['rows'])->keyBy('source_enrollment_id');
        $sources = $this->rosterQuery($command)->whereIn('id', $commands->keys())->with('student')->orderBy('roll_number')->orderBy('id')->lockForUpdate()->get();
        $eligible = $this->eligibleQuery($command)->whereIn('id', $commands->keys())->pluck('id');
        if ($sources->count() !== $commands->count() || $eligible->count() !== $commands->count()) {
            throw ValidationException::withMessages(['rows' => 'Some selected students are no longer eligible or belong to another section. Reload the student selection.']);
        }
        $destinations = [];
        $rows = [];
        $reviews = [];
        $revisions = [];
        foreach ($sources as $source) {
            $row = $commands[$source->id];
            $sectionId = $row['target_section_id'] ?? $defaultSection->id;
            if (! isset($destinations[$sectionId])) {
                $section = Section::eligible()->with('schoolClass')->findOrFail($sectionId);
                $reserved = StudentEnrollment::where('academic_year_id', $targetYear->id)->where('section_id', $section->id)->orderBy('id')->get(['id', 'student_id', 'roll_number', 'status']);
                $destinations[$sectionId] = ['section' => $section, 'reserved' => $reserved, 'manual' => [], 'next' => (int) $reserved->max('roll_number'), 'selected' => 0];
            }
            $destination = &$destinations[$sectionId];
            $section = $destination['section'];
            $sameClass = (int) $section->class_id === (int) $sourceClass->id;
            if (($row['action'] === 'REPEAT') !== $sameClass || ($row['action'] === 'PROMOTE' && (int) $section->class_id !== (int) $defaultClass->id)) {
                throw ValidationException::withMessages(['rows' => 'Repeating students must stay in the source class. Promoted students must use the chosen destination class.']);
            }
            if ($command['joined_at'] < $source->student->admission_date->format('Y-m-d')) {
                throw ValidationException::withMessages(['joined_at' => 'The joining date cannot precede a selected student’s admission date.']);
            }
            $roll = isset($row['roll_number']) ? (int) $row['roll_number'] : null;
            if ($roll !== null) {
                if (in_array($roll, $destination['manual'], true) || $destination['reserved']->contains('roll_number', $roll)) {
                    throw ValidationException::withMessages(['rows' => 'Roll '.$roll.' is already reserved in '.$section->schoolClass->name.' / '.$section->name.'. Choose another number or leave it automatic.']);
                }
                $destination['manual'][] = $roll;
            }
            $destination['selected']++;
            $rows[] = ['source_enrollment_id' => $source->id, 'action' => $row['action'], 'target_section_id' => $section->id, 'roll_number' => $roll, 'notes' => trim($row['notes'] ?? '') ?: null];
            $reviews[] = ['name' => $source->student->fullName(), 'admission_number' => $source->student->admission_number, 'action' => $row['action'], 'class_name' => $section->schoolClass->name, 'section_name' => $section->name];
            $revisions[] = StudentEnrollmentState::revision($year, $source->student, $source);
            unset($destination);
        }
        foreach ($destinations as $destination) {
            $occupied = $destination['reserved']->where('status.value', 'ACTIVE')->count();
            if ($destination['section']->capacity !== null && $occupied + $destination['selected'] > $destination['section']->capacity) {
                throw ValidationException::withMessages(['rows' => $destination['section']->schoolClass->name.' / '.$destination['section']->name.' has insufficient seats for this selection.']);
            }
        }
        foreach ($rows as $index => &$row) {
            $destination = &$destinations[$row['target_section_id']];
            if ($row['roll_number'] === null) {
                do {
                    $destination['next']++;
                } while (in_array($destination['next'], $destination['manual'], true));
                if ($destination['next'] > 4294967295) {
                    throw ValidationException::withMessages(['rows' => 'No automatic roll numbers remain in a destination section. Enter unused roll numbers manually.']);
                }
                $row['roll_number'] = $destination['next'];
            }
            $reviews[$index]['roll_number'] = $row['roll_number'];
            unset($destination);
        }
        unset($row);
        $payload = Arr::only($command, ['operation_key', 'source_academic_year_id', 'source_class_id', 'source_section_id', 'target_academic_year_id', 'target_class_id', 'target_section_id', 'joined_at']) + ['mode' => 'PLACEMENT', 'confirmed' => true, 'rows' => $rows];
        $snapshot = [$year->getAttributes(), $targetYear->getAttributes(), $sourceClass->getAttributes(), $sourceSection->getAttributes(), $defaultClass->getAttributes(), $defaultSection->getAttributes(), $revisions, $payload];
        foreach ($destinations as $destination) {
            $snapshot[] = [$destination['section']->getAttributes(), $destination['section']->schoolClass->getAttributes(), $destination['reserved']->map(fn ($record) => $record->getAttributes())->all()];
        }
        $promoted = collect($rows)->where('action', 'PROMOTE')->count();

        return ['payload' => $payload, 'snapshot' => hash('sha256', json_encode($snapshot)), 'rows' => $reviews, 'summary' => ['promoted' => $promoted, 'repeating' => count($rows) - $promoted, 'left_for_later' => $this->eligibleQuery($command)->count() - count($rows)], 'source' => $year->name.' · '.$sourceClass->name.' / '.$sourceSection->name, 'target_year' => $targetYear->name, 'joined_at' => $command['joined_at']];
    }

    public function review(User $actor, array $input): array
    {
        $this->authorize($actor);
        $command = Validator::make($input, StudentProgressionRules::review())->validate();

        return DB::transaction(function () use ($actor, $command) {
            School::whereKey(app(CurrentSchool::class)->id())->lockForUpdate()->firstOrFail();
            $this->authorize($actor);
            $review = $this->prepare($command);
            $token = Crypt::encryptString(json_encode(['version' => 1, 'actor' => $actor->id, 'school' => app(CurrentSchool::class)->id(), 'expires' => now()->addMinutes(30)->timestamp, 'command' => $command, 'payload' => $review['payload'], 'snapshot' => $review['snapshot']], JSON_THROW_ON_ERROR));

            return Arr::except($review, ['payload', 'snapshot']) + ['review_token' => $token];
        });
    }

    public function confirm(User $actor, string $token): PromotionBatch
    {
        $this->authorize($actor);
        try {
            $review = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException $exception) {
            throw ValidationException::withMessages(['review_token' => 'This review is invalid. Review the student selection again.']);
        }
        if (! is_array($review) || ($review['version'] ?? null) !== 1 || ($review['school'] ?? null) !== app(CurrentSchool::class)->id() || ($review['actor'] ?? null) !== $actor->id) {
            throw ValidationException::withMessages(['review_token' => 'This review belongs to another session or school. Review the student selection again.']);
        }

        return DB::transaction(function () use ($actor, $review) {
            School::whereKey(app(CurrentSchool::class)->id())->lockForUpdate()->firstOrFail();
            $this->authorize($actor);
            $existing = PromotionBatch::where('operation_key', $review['payload']['operation_key'])->first();
            if (! $existing) {
                if ($review['expires'] < now()->timestamp) {
                    throw ValidationException::withMessages(['review_token' => 'This review expired. Review the student selection again.']);
                }
                $latest = $this->prepare($review['command']);
                if (! hash_equals($review['snapshot'], $latest['snapshot'])) {
                    throw ValidationException::withMessages(['review_token' => 'Student or destination details changed after review. Review again before confirming; nothing was saved.']);
                }
            }

            return app(PromotionService::class)->process($actor, $review['payload']);
        });
    }
}
