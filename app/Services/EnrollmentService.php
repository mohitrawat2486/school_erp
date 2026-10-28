<?php

namespace App\Services;

use App\Enums\AcademicYearStatus;
use App\Enums\ClassStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\SectionStatus;
use App\Enums\StudentStatus;
use App\Models\AcademicYear;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\User;
use App\Support\CurrentSchool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class EnrollmentService
{
    private function authorize(User $actor, ?StudentEnrollment $record): void
    {
        $actor = $actor->fresh();
        abort_unless($actor, 403);
        Gate::forUser($actor)->authorize($record ? 'update' : 'create', $record ?? StudentEnrollment::class);
    }

    private function context(array $ids, bool $active): array
    {
        $year = AcademicYear::whereKey($ids['academic_year_id'])->lockForUpdate()->firstOrFail();
        if ($year->status !== AcademicYearStatus::Active) {
            throw ValidationException::withMessages(['academic_year_id' => 'Enrollments can only be changed in an active academic year.']);
        }
        $student = Student::whereKey($ids['student_id'])->lockForUpdate()->firstOrFail();
        $class = SchoolClass::whereKey($ids['class_id'])->lockForUpdate()->firstOrFail();
        $section = Section::whereKey($ids['section_id'])->lockForUpdate()->firstOrFail();
        if ((int) $section->class_id !== (int) $class->id) {
            throw ValidationException::withMessages(['section_id' => 'The section must belong to the selected class.']);
        }
        if ($active && ($student->status !== StudentStatus::Active || $class->status !== ClassStatus::Active || $section->status !== SectionStatus::Active)) {
            throw ValidationException::withMessages(['context' => 'An active student, class and section are required.']);
        }

        return [$year, $student, $section];
    }

    private function validatePlacement(StudentEnrollment $record, AcademicYear $year, Student $student, Section $section): void
    {
        Validator::make(['joined_at' => $record->joined_at->format('Y-m-d')], ['joined_at' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$year->start_date->format('Y-m-d'), 'before_or_equal:'.$year->end_date->format('Y-m-d'), 'after_or_equal:'.$student->admission_date->format('Y-m-d')]])->validate();
        $duplicate = StudentEnrollment::where('academic_year_id', $record->academic_year_id)->where('class_id', $record->class_id)->where('section_id', $record->section_id)->where('roll_number', $record->roll_number);
        if ($record->exists) {
            $duplicate->whereKeyNot($record->id);
        }
        if ($duplicate->exists()) {
            throw ValidationException::withMessages(['roll_number' => 'This roll number is already reserved in the selected year, class and section.']);
        }
        if ($record->status === EnrollmentStatus::Active && $section->capacity !== null) {
            $count = StudentEnrollment::where('academic_year_id', $year->id)->where('section_id', $section->id)->where('status', 'ACTIVE');
            if ($record->exists) {
                $count->whereKeyNot($record->id);
            }
            if ($count->count() >= $section->capacity) {
                throw ValidationException::withMessages(['section_id' => 'This section has reached its capacity for the academic year.']);
            }
        }
    }

    public function save(User $actor, ?StudentEnrollment $enrollment, array $data): StudentEnrollment
    {
        $this->authorize($actor, $enrollment);
        Validator::make($data, ['roll_number' => ['required', 'integer', 'between:1,4294967295'], 'joined_at' => ['required', 'date_format:Y-m-d']])->validate();

        return DB::transaction(function () use ($actor, $enrollment, $data) {
            School::whereKey(app(CurrentSchool::class)->id())->lockForUpdate()->firstOrFail();
            $this->authorize($actor, $enrollment);
            $record = $enrollment ? StudentEnrollment::whereKey($enrollment->id)->lockForUpdate()->firstOrFail() : new StudentEnrollment;
            $creating = ! $record->exists;
            if (! $creating) {
                $this->authorize($actor, $record);
            }
            if (! $creating && array_intersect(StudentEnrollment::CONTEXT, array_keys($data))) {
                throw ValidationException::withMessages(['context' => 'The student, year, class and section cannot be changed on an existing enrollment.']);
            }
            if ($creating) {
                Validator::make($data, array_fill_keys(StudentEnrollment::CONTEXT, ['required', 'integer', 'min:1']))->validate();
                foreach (StudentEnrollment::CONTEXT as $field) {
                    $record->$field = $data[$field];
                }
            }
            [$year,$student,$section] = $this->context($record->only(StudentEnrollment::CONTEXT), $record->status === EnrollmentStatus::Active);
            if ($creating && StudentEnrollment::where('student_id', $student->id)->where('academic_year_id', $year->id)->exists()) {
                throw ValidationException::withMessages(['student_id' => 'This student already has an enrollment for this academic year. View or reactivate the existing record.']);
            }
            $before = $creating ? [] : $record->only(StudentEnrollment::AUDIT_FIELDS);
            $record->fill(array_intersect_key($data, array_flip(['roll_number', 'joined_at'])));
            $this->validatePlacement($record, $year, $student, $section);
            if (! $creating && ! $record->isDirty()) {
                return $record;
            }$record->save();
            app(AuditLogger::class)->record($actor, $creating ? 'ENROLLMENT_CREATED' : 'ENROLLMENT_UPDATED', 'student_enrollment', $record->id, $before, $record->only(StudentEnrollment::AUDIT_FIELDS), 'enrollments');

            return $record;
        });
    }

    public function setStatus(User $actor, StudentEnrollment $enrollment, EnrollmentStatus $status): void
    {
        $this->authorize($actor, $enrollment);
        DB::transaction(function () use ($actor, $enrollment, $status) {
            School::whereKey(app(CurrentSchool::class)->id())->lockForUpdate()->firstOrFail();
            $this->authorize($actor, $enrollment);
            $record = StudentEnrollment::whereKey($enrollment->id)->lockForUpdate()->firstOrFail();
            $this->authorize($actor, $record);
            if (! in_array($status, [EnrollmentStatus::Active, EnrollmentStatus::Inactive], true)) {
                throw ValidationException::withMessages(['status' => 'Use the promotion exit workflow for withdrawal or transfer.']);
            }
            [$year,$student,$section] = $this->context($record->only(StudentEnrollment::CONTEXT), $status === EnrollmentStatus::Active);
            if ($record->status === $status) {
                return;
            }$before = $record->only(StudentEnrollment::AUDIT_FIELDS);
            $record->status = $status;
            if ($status === EnrollmentStatus::Active) {
                $this->validatePlacement($record, $year, $student, $section);
            }
            $record->save();
            app(AuditLogger::class)->record($actor, $status === EnrollmentStatus::Active ? 'ENROLLMENT_ACTIVATED' : 'ENROLLMENT_DEACTIVATED', 'student_enrollment', $record->id, $before, $record->only(StudentEnrollment::AUDIT_FIELDS), 'enrollments');
        });
    }
}
