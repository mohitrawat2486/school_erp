<?php

namespace App\Services;

use App\Enums\AcademicYearStatus;
use App\Enums\AssignmentType;
use App\Enums\ClassStatus;
use App\Enums\ClassSubjectStatus;
use App\Enums\SubjectStatus;
use App\Enums\TeacherAssignmentStatus;
use App\Models\AcademicYear;
use App\Models\ClassSubject;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\TeacherAssignment;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Support\CurrentSchool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class TeacherAssignmentService
{
    private function guard(User $actor, ?TeacherAssignment $assignment): void
    {
        $fresh = $actor->fresh();
        abort_unless($fresh, 403);
        Gate::forUser($fresh)->authorize($assignment ? 'update' : 'create', $assignment ?? TeacherAssignment::class);
    }

    private function lockSchool(): void
    {
        School::whereKey(app(CurrentSchool::class)->id())->lockForUpdate()->firstOrFail();
    }

    private function context(array $data, bool $active): array
    {
        $year = AcademicYear::whereKey($data['academic_year_id'])->lockForUpdate()->firstOrFail();
        if (! in_array($year->status, [AcademicYearStatus::Draft, AcademicYearStatus::Active], true)) {
            throw ValidationException::withMessages(['academic_year_id' => 'Closed and archived academic years are read-only.']);
        }
        $teacher = TeacherProfile::with('employee')->whereKey($data['teacher_id'])->lockForUpdate()->firstOrFail();
        $class = SchoolClass::whereKey($data['class_id'])->lockForUpdate()->firstOrFail();
        $section = isset($data['section_id']) ? Section::with('schoolClass')->where('class_id', $class->id)->whereKey($data['section_id'])->lockForUpdate()->firstOrFail() : null;
        $type = AssignmentType::from($data['assignment_type'] instanceof AssignmentType ? $data['assignment_type']->value : $data['assignment_type']);
        if ($type === AssignmentType::ClassTeacher && isset($data['subject_id'])) {
            throw ValidationException::withMessages(['subject_id' => 'Class teacher assignments do not select a subject.']);
        }
        $curriculum = null;
        if ($type === AssignmentType::SubjectTeacher) {
            if (! isset($data['subject_id'])) {
                throw ValidationException::withMessages(['subject_id' => 'Choose a subject allocated to this class and year.']);
            }
            $curriculum = ClassSubject::with('subject')->where('academic_year_id', $year->id)->where('class_id', $class->id)->where('subject_id', $data['subject_id'])->lockForUpdate()->firstOrFail();
        }
        if ($active && (! $teacher->isEligible() || $class->status !== ClassStatus::Active || ($section && ! $section->isEligible()) || ($curriculum && ($curriculum->status !== ClassSubjectStatus::Active || $curriculum->subject->status !== SubjectStatus::Active)))) {
            throw ValidationException::withMessages(['eligibility' => 'Choose an active teacher/employee, class, section and subject allocation.']);
        }

        return ['academic_year_id' => $year->id, 'teacher_id' => $teacher->id, 'class_id' => $class->id, 'section_id' => $section?->id, 'subject_id' => $curriculum?->subject_id, 'class_subject_id' => $curriculum?->id, 'assignment_type' => $type->value];
    }

    private function classTeacherConflict(array $identity, ?int $except = null): void
    {
        if ($identity['assignment_type'] !== AssignmentType::ClassTeacher->value) {
            return;
        }
        $query = TeacherAssignment::where('academic_year_id', $identity['academic_year_id'])->where('class_id', $identity['class_id'])->where('assignment_type', AssignmentType::ClassTeacher->value)->where('status', TeacherAssignmentStatus::Active->value);
        if ($except) {
            $query->whereKeyNot($except);
        }
        if ($identity['section_id'] !== null) {
            $query->where(fn ($q) => $q->whereNull('section_id')->orWhere('section_id', $identity['section_id']));
        }
        if ($query->exists()) {
            throw ValidationException::withMessages(['conflict' => 'An active class teacher already covers this class/section. Deactivate that assignment before replacing it.']);
        }
    }

    public function create(User $actor, array $data): TeacherAssignment
    {
        $this->guard($actor, null);

        return DB::transaction(function () use ($actor, $data) {
            $this->lockSchool();
            $this->guard($actor, null);
            $identity = $this->context($data, true);
            $duplicate = TeacherAssignment::query();
            foreach (TeacherAssignment::IDENTITY as $field) {
                $duplicate->where($field, $identity[$field]);
            }
            if ($duplicate->exists()) {
                throw ValidationException::withMessages(['duplicate' => 'This assignment already exists. Reactivate the retained record if needed.']);
            }
            $this->classTeacherConflict($identity);
            $assignment = new TeacherAssignment;
            foreach ($identity as $field => $value) {
                $assignment->$field = $value;
            }
            $assignment->save();
            app(AuditLogger::class)->record($actor, 'TEACHER_ASSIGNMENT_CREATED', 'teacher_assignment', $assignment->id, [], $assignment->only(TeacherAssignment::AUDIT_FIELDS), 'academics');

            return $assignment;
        });
    }

    public function setStatus(User $actor, TeacherAssignment $assignment, TeacherAssignmentStatus $status): void
    {
        $this->guard($actor, $assignment);
        DB::transaction(function () use ($actor, $assignment, $status) {
            $this->lockSchool();
            $this->guard($actor, $assignment);
            $record = TeacherAssignment::whereKey($assignment->id)->lockForUpdate()->firstOrFail();
            $identity = $this->context($record->only(TeacherAssignment::IDENTITY), $status === TeacherAssignmentStatus::Active);
            if ($record->status === $status) {
                return;
            }
            if ($status === TeacherAssignmentStatus::Active) {
                $this->classTeacherConflict($identity, $record->id);
            }
            $old = $record->only(TeacherAssignment::AUDIT_FIELDS);
            $record->status = $status;
            $record->save();
            app(AuditLogger::class)->record($actor, $status === TeacherAssignmentStatus::Active ? 'TEACHER_ASSIGNMENT_ACTIVATED' : 'TEACHER_ASSIGNMENT_DEACTIVATED', 'teacher_assignment', $record->id, $old, $record->only(TeacherAssignment::AUDIT_FIELDS), 'academics');
        });
    }
}
