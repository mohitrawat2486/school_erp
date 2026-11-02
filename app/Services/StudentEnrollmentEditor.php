<?php

namespace App\Services;

use App\Enums\EnrollmentStatus;
use App\Models\AcademicYear;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\User;
use App\Support\StudentEnrollmentRules;
use App\Support\StudentEnrollmentState;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class StudentEnrollmentEditor
{
    // Called inside StudentService's transaction and school lock, before student/guardian edits.
    public function prepare(User $actor, ?Student $student, array $input): ?array
    {
        $data = Validator::make($input, StudentEnrollmentRules::rules($input))->validate()['enrollment'] ?? [];
        if (($data['action'] ?? 'keep') !== 'save') {
            return null;
        }
        $year = AcademicYear::whereKey($data['academic_year_id'])->lockForUpdate()->firstOrFail();
        $record = $student?->enrollments()->where('academic_year_id', $year->id)->lockForUpdate()->first();
        Gate::forUser($actor->fresh())->authorize($record ? 'update' : 'create', $record ?? StudentEnrollment::class);
        if (! hash_equals(StudentEnrollmentState::revision($year, $student, $record), $data['revision'])) {
            throw ValidationException::withMessages(['enrollment.revision' => 'Student or enrollment details changed while this form was open. Latest enrollment details have been loaded. Review them and save again.']);
        }
        $state = StudentEnrollmentState::data($year, $student);
        if (! $state['can_write']) {
            throw ValidationException::withMessages(['enrollment' => $state['reason']]);
        }
        $class = SchoolClass::active()->whereKey($data['class_id'])->first();
        $section = Section::eligible()->where('class_id', $data['class_id'])->whereKey($data['section_id'])->first();
        if (! $class || ! $section) {
            throw ValidationException::withMessages(['enrollment.section_id' => 'Choose an active section belonging to the selected class.']);
        }
        $changing = $record && ((int) $data['class_id'] !== (int) $record->class_id || (int) $data['section_id'] !== (int) $record->section_id);
        if ($changing && (empty($data['correct_placement']) || ! $state['can_correct'])) {
            throw ValidationException::withMessages(['enrollment.class_id' => $state['placement_reason'] ?? 'Use Change class / section to confirm a placement correction.']);
        }
        if ($data['roll_mode'] === 'auto') {
            $last = (int) StudentEnrollment::where('academic_year_id', $year->id)->where('class_id', $class->id)->where('section_id', $section->id)->max('roll_number');
            if ($last >= 4294967295) {
                throw ValidationException::withMessages(['enrollment.roll_number' => 'No automatic roll numbers remain in this section. Enter an unused roll number manually.']);
            }
            $data['roll_number'] = $last + 1;
        }

        return ['record' => $record, 'data' => $data, 'changing' => (bool) $changing];
    }

    public function apply(User $actor, Student $student, ?array $prepared): void
    {
        if (! $prepared) {
            return;
        }
        $data = $prepared['data'];
        $record = $prepared['record'];
        $details = ['roll_number' => $data['roll_number'], 'joined_at' => $data['joined_at']];
        $service = app(EnrollmentService::class);
        try {
            if (! $record) {
                $service->save($actor, null, $details + ['student_id' => $student->id, 'academic_year_id' => $data['academic_year_id'], 'class_id' => $data['class_id'], 'section_id' => $data['section_id']]);
            } else {
                if ($prepared['changing']) {
                    $record = $service->correctPlacement($actor, $record, $details + ['class_id' => $data['class_id'], 'section_id' => $data['section_id']]);
                } else {
                    $record = $service->save($actor, $record, $details);
                }
                if (! empty($data['reactivate']) && $record->status->value === 'INACTIVE') {
                    $service->setStatus($actor, $record, EnrollmentStatus::Active);
                }
            }
        } catch (ValidationException $exception) {
            $messages = [];
            foreach ($exception->errors() as $key => $errors) {
                $messages['enrollment.'.$key] = $errors;
            }
            throw ValidationException::withMessages($messages);
        }
    }
}
