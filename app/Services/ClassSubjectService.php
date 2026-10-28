<?php

namespace App\Services;

use App\Enums\AcademicYearStatus;
use App\Enums\ClassStatus;
use App\Enums\ClassSubjectStatus;
use App\Enums\SubjectStatus;
use App\Models\AcademicYear;
use App\Models\ClassSubject;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use App\Support\CurrentSchool;
use App\Support\MarksConfiguration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ClassSubjectService
{
    private function authorize(User $actor, ?ClassSubject $allocation): void
    {
        $actor = $actor->fresh();
        abort_unless($actor, 403);
        Gate::forUser($actor)->authorize($allocation ? 'update' : 'create', $allocation ?? ClassSubject::class);
    }

    private function lockSchool(): void
    {
        School::whereKey(app(CurrentSchool::class)->id())->lockForUpdate()->firstOrFail();
    }

    private function context(array $ids, bool $requireActive): array
    {
        $year = AcademicYear::whereKey($ids['academic_year_id'])->lockForUpdate()->firstOrFail();
        if (! in_array($year->status, [AcademicYearStatus::Draft, AcademicYearStatus::Active], true)) {
            throw ValidationException::withMessages(['academic_year_id' => 'Closed and archived academic years are read-only.']);
        }
        $class = SchoolClass::whereKey($ids['class_id'])->lockForUpdate()->firstOrFail();
        $subject = Subject::whereKey($ids['subject_id'])->lockForUpdate()->firstOrFail();
        if ($requireActive && ($class->status !== ClassStatus::Active || $subject->status !== SubjectStatus::Active)) {
            throw ValidationException::withMessages(['context' => 'An active class and subject are required.']);
        }

        return ['academic_year_id' => $year->id, 'class_id' => $class->id, 'subject_id' => $subject->id];
    }

    public function save(User $actor, ?ClassSubject $allocation, array $data): ClassSubject
    {
        $this->authorize($actor, $allocation);

        return DB::transaction(function () use ($actor, $allocation, $data) {
            $this->lockSchool();
            $this->authorize($actor, $allocation);
            $record = $allocation ? ClassSubject::whereKey($allocation->id)->lockForUpdate()->firstOrFail() : new ClassSubject;
            $creating = ! $record->exists;
            $fields = ['academic_year_id', 'class_id', 'subject_id'];
            if (! $creating && array_intersect($fields, array_keys($data))) {
                throw ValidationException::withMessages(['context' => 'An existing allocation cannot be moved to a different year, class or subject.']);
            }
            $ids = $this->context($creating ? $data : $record->only($fields), $creating);
            if ($creating && ClassSubject::where($ids)->exists()) {
                throw ValidationException::withMessages(['subject_id' => 'This subject is already allocated to the class for this academic year.']);
            }
            $old = $creating ? [] : $record->only(ClassSubject::AUDIT_FIELDS);
            foreach ($ids as $field => $id) {
                $record->$field = $id;
            }
            $record->fill(MarksConfiguration::validate($data, $record->only(MarksConfiguration::FIELDS)));
            if (! $creating && ! $record->isDirty()) {
                return $record;
            } $record->save();
            app(AuditLogger::class)->record($actor, $creating ? 'CLASS_SUBJECT_CREATED' : 'CLASS_SUBJECT_UPDATED', 'class_subject', $record->id, $old, $record->only(ClassSubject::AUDIT_FIELDS), 'academics');

            return $record;
        });
    }

    public function setStatus(User $actor, ClassSubject $allocation, ClassSubjectStatus $status): void
    {
        $this->authorize($actor, $allocation);
        DB::transaction(function () use ($actor, $allocation, $status) {
            $this->lockSchool();
            $this->authorize($actor, $allocation);
            $record = ClassSubject::whereKey($allocation->id)->lockForUpdate()->firstOrFail();
            $this->context($record->only(['academic_year_id', 'class_id', 'subject_id']), $status === ClassSubjectStatus::Active);
            if ($record->status === $status) {
                return;
            } $old = $record->only(ClassSubject::AUDIT_FIELDS);
            $record->status = $status;
            $record->save();
            app(AuditLogger::class)->record($actor, $status === ClassSubjectStatus::Active ? 'CLASS_SUBJECT_ACTIVATED' : 'CLASS_SUBJECT_DEACTIVATED', 'class_subject', $record->id, $old, $record->only(ClassSubject::AUDIT_FIELDS), 'academics');
        });
    }
}
