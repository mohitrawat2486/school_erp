<?php

namespace App\Services;

use App\Enums\EmployeeStatus;
use App\Enums\TeacherProfileStatus;
use App\Models\Employee;
use App\Models\School;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Support\CurrentSchool;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class TeacherProfileService
{
    private function authorize(User $actor, ?TeacherProfile $profile): void
    {
        $actor = $actor->fresh();
        abort_unless($actor, 403);
        Gate::forUser($actor)->authorize($profile ? 'update' : 'create', $profile ?? TeacherProfile::class);
    }

    private function lockSchool(): void
    {
        School::whereKey(app(CurrentSchool::class)->id())->lockForUpdate()->firstOrFail();
    }

    private function ensureEmployeeActive(Employee $employee): void
    {
        if ($employee->status !== EmployeeStatus::Active) {
            throw ValidationException::withMessages(['employee_id' => 'An active employee is required.']);
        }
    }

    public function save(User $actor, ?TeacherProfile $profile, array $data): TeacherProfile
    {
        $this->authorize($actor, $profile);

        return DB::transaction(function () use ($actor, $profile, $data) {
            $this->lockSchool();
            $this->authorize($actor, $profile);
            $record = $profile ? TeacherProfile::whereKey($profile->id)->lockForUpdate()->firstOrFail() : new TeacherProfile;
            $employee = Employee::whereKey($profile ? $record->employee_id : $data['employee_id'])->lockForUpdate()->firstOrFail();
            $creating = ! $record->exists;
            if ($creating) {
                $this->ensureEmployeeActive($employee);
                if (TeacherProfile::where('employee_id', $employee->id)->exists()) {
                    throw ValidationException::withMessages(['employee_id' => 'This employee already has a teacher profile.']);
                }
                $record->employee_id = $employee->id;
            } elseif (array_key_exists('employee_id', $data)) {
                throw ValidationException::withMessages(['employee_id' => 'The linked employee cannot be changed.']);
            }
            $old = $creating ? [] : $record->only(TeacherProfile::AUDIT_FIELDS);
            $record->fill(Arr::only($data, ['qualification', 'specialization', 'experience']));
            if (! $creating && ! $record->isDirty()) {
                return $record;
            }
            $record->save();
            app(AuditLogger::class)->record($actor, $creating ? 'TEACHER_PROFILE_CREATED' : 'TEACHER_PROFILE_UPDATED', 'teacher_profile', $record->id, $old, $record->only(TeacherProfile::AUDIT_FIELDS), 'teachers');

            return $record;
        });
    }

    public function setStatus(User $actor, TeacherProfile $profile, TeacherProfileStatus $status): void
    {
        $this->authorize($actor, $profile);
        DB::transaction(function () use ($actor, $profile, $status) {
            $this->lockSchool();
            $this->authorize($actor, $profile);
            $record = TeacherProfile::whereKey($profile->id)->lockForUpdate()->firstOrFail();
            $employee = Employee::whereKey($record->employee_id)->lockForUpdate()->firstOrFail();
            if ($status === TeacherProfileStatus::Active) {
                $this->ensureEmployeeActive($employee);
            }
            if ($record->status === $status) {
                return;
            }
            $old = $record->only(TeacherProfile::AUDIT_FIELDS);
            $record->status = $status;
            $record->save();
            app(AuditLogger::class)->record($actor, $status === TeacherProfileStatus::Active ? 'TEACHER_PROFILE_ACTIVATED' : 'TEACHER_PROFILE_DEACTIVATED', 'teacher_profile', $record->id, $old, $record->only(TeacherProfile::AUDIT_FIELDS), 'teachers');
        });
    }
}
