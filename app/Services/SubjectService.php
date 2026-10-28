<?php

namespace App\Services;

use App\Enums\SubjectStatus;
use App\Models\School;
use App\Models\Subject;
use App\Models\User;
use App\Support\CurrentSchool;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class SubjectService
{
    private function authorize(User $actor, ?Subject $subject): void
    {
        $actor = $actor->fresh();
        abort_unless($actor, 403);
        Gate::forUser($actor)->authorize($subject ? 'update' : 'create', $subject ?? Subject::class);
    }

    private function lockSchool(): void
    {
        School::whereKey(app(CurrentSchool::class)->id())->lockForUpdate()->firstOrFail();
    }

    public function save(User $actor, ?Subject $subject, array $data): Subject
    {
        $this->authorize($actor, $subject);

        return DB::transaction(function () use ($actor, $subject, $data) {
            $this->lockSchool();
            $this->authorize($actor, $subject);
            $record = $subject ? Subject::whereKey($subject->id)->lockForUpdate()->firstOrFail() : new Subject;
            $creating = ! $record->exists;
            $query = Subject::where('code', $data['code']);
            if (! $creating) {
                $query->whereKeyNot($record->id);
            }
            if ($query->exists()) {
                throw ValidationException::withMessages(['code' => 'This subject code is already in use.']);
            }
            $old = $creating ? [] : $record->only(Subject::AUDIT_FIELDS);
            $record->fill(Arr::only($data, ['name', 'code', 'type']));
            if (! $creating && ! $record->isDirty()) {
                return $record;
            } $record->save();
            app(AuditLogger::class)->record($actor, $creating ? 'SUBJECT_CREATED' : 'SUBJECT_UPDATED', 'subject', $record->id, $old, $record->only(Subject::AUDIT_FIELDS), 'academics');

            return $record;
        });
    }

    public function setStatus(User $actor, Subject $subject, SubjectStatus $status): void
    {
        $this->authorize($actor, $subject);
        DB::transaction(function () use ($actor, $subject, $status) {
            $this->lockSchool();
            $this->authorize($actor, $subject);
            $record = Subject::whereKey($subject->id)->lockForUpdate()->firstOrFail();
            if ($record->status === $status) {
                return;
            } $old = $record->only(Subject::AUDIT_FIELDS);
            $record->status = $status;
            $record->save();
            app(AuditLogger::class)->record($actor, $status === SubjectStatus::Active ? 'SUBJECT_ACTIVATED' : 'SUBJECT_DEACTIVATED', 'subject', $record->id, $old, $record->only(Subject::AUDIT_FIELDS), 'academics');
        });
    }
}
