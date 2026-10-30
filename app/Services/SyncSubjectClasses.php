<?php

namespace App\Services;

use App\Enums\ClassSubjectStatus;
use App\Http\Requests\SaveSubjectClassesRequest;
use App\Models\AcademicYear;
use App\Models\ClassSubject;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use App\Support\CurrentSchool;
use App\Support\SubjectClassState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SyncSubjectClasses
{
    public function save(User $actor, Subject $subject, array $data): array
    {
        abort_unless((int) $subject->school_id === app(CurrentSchool::class)->id(), 403);

        return DB::transaction(function () use ($actor, $subject, $data) {
            School::whereKey(app(CurrentSchool::class)->id())->lockForUpdate()->firstOrFail();
            $actor = $actor->fresh();
            abort_unless($actor && (int) $actor->school_id === app(CurrentSchool::class)->id(), 403);
            Gate::forUser($actor)->authorize('create', ClassSubject::class);
            Validator::make($data, SaveSubjectClassesRequest::membershipRules())->validate();
            $subject = Subject::whereKey($subject->id)->lockForUpdate()->firstOrFail();
            $year = AcademicYear::whereKey($data['academic_year_id'])->lockForUpdate()->firstOrFail();
            if ($subject->status->value !== 'ACTIVE') {
                throw ValidationException::withMessages(['subject' => 'Activate this subject before changing its classes.']);
            }
            if (! hash_equals(SubjectClassState::revision($subject, $year), $data['setup_revision'])) {
                throw ValidationException::withMessages(['setup_revision' => 'This setup changed since you opened it. Review the latest class selections before saving again.']);
            }
            $selected = array_map('intval', $data['class_ids']);
            $existing = ClassSubject::where('academic_year_id', $year->id)->where('subject_id', $subject->id)->with('schoolClass')->lockForUpdate()->get();
            $remove = $existing->filter(fn ($row) => $row->status === ClassSubjectStatus::Active && ! in_array($row->class_id, $selected, true));
            foreach ($remove as $row) {
                if (SubjectClassState::isUsed($row)) {
                    throw ValidationException::withMessages(['class_ids' => $row->schoolClass->name.' is used by teachers or exams. Keep it selected to preserve those links.']);
                }
            }
            if ($remove->isNotEmpty() && empty($data['confirm_removals'])) {
                throw ValidationException::withMessages(['confirm_removals' => 'Review and confirm the classes being removed before saving.']);
            }
            $classes = SchoolClass::whereIn('id', $selected)->get()->keyBy('id');
            $counts = ['added' => 0, 'reactivated' => 0, 'removed' => 0];
            $service = app(ClassSubjectService::class);
            foreach ($selected as $id) {
                $record = $existing->firstWhere('class_id', $id);
                if ($record?->status === ClassSubjectStatus::Active) {
                    continue;
                }
                if ($classes->get($id)?->status->value !== 'ACTIVE') {
                    throw ValidationException::withMessages(['class_ids' => 'Only active classes can receive a new or reactivated subject.']);
                }
                if ($record) {
                    $service->setStatus($actor, $record, ClassSubjectStatus::Active);
                    $counts['reactivated']++;
                } else {
                    $service->save($actor, null, ['academic_year_id' => $year->id, 'class_id' => $id, 'subject_id' => $subject->id]);
                    $counts['added']++;
                }
            }
            foreach ($remove as $row) {
                $service->setStatus($actor, $row, ClassSubjectStatus::Inactive);
                $counts['removed']++;
            }

            return $counts;
        });
    }
}
