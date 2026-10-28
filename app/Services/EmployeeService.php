<?php

namespace App\Services;

use App\Enums\EmployeeStatus;
use App\Enums\TeacherProfileStatus;
use App\Models\Employee;
use App\Models\School;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Support\CurrentSchool;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class EmployeeService
{
    public const FIELDS = ['employee_number', 'first_name', 'middle_name', 'last_name', 'employee_type', 'email', 'phone', 'alternate_phone', 'gender', 'date_of_birth', 'joining_date', 'designation', 'department', 'address'];

    private function lockSchool(): void
    {
        School::whereKey(app(CurrentSchool::class)->id())->lockForUpdate()->firstOrFail();
    }

    private function snapshot(Employee $employee): array
    {
        return Arr::only($employee->getAttributes(), [...self::FIELDS, 'status', 'photo_path']);
    }

    private function authorize(User $actor, ?Employee $employee): void
    {
        $actor = $actor->fresh();
        abort_unless($actor, 403);
        Gate::forUser($actor)->authorize($employee ? 'update' : 'create', $employee ?? Employee::class);
    }

    public function save(User $actor, ?Employee $employee, array $data, ?UploadedFile $photo = null): Employee
    {
        $this->authorize($actor, $employee);
        $newPath = null;
        try {
            if ($photo) {
                $newPath = $photo->store('schools/'.app(CurrentSchool::class)->id().'/employees/photos', 'local');
                if (! $newPath) {
                    throw new RuntimeException('Employee photo could not be stored.');
                }
            }

            return DB::transaction(function () use ($actor, $employee, $data, $newPath) {
                $this->lockSchool();
                $this->authorize($actor, $employee);
                $creating = $employee === null;
                $record = $employee ? Employee::whereKey($employee->id)->lockForUpdate()->firstOrFail() : new Employee;
                $duplicate = Employee::where('employee_number', $data['employee_number']);
                if ($record->exists) {
                    $duplicate->whereKeyNot($record->id);
                }
                if ($duplicate->exists()) {
                    throw ValidationException::withMessages(['employee_number' => 'This employee number is already in use.']);
                }
                $old = $record->exists ? $this->snapshot($record) : [];
                $record->fill(Arr::only($data, self::FIELDS));
                if ($newPath) {
                    $record->photo_path = $newPath;
                }
                if (! $creating && ! $record->isDirty()) {
                    return $record;
                }
                $record->save();
                app(AuditLogger::class)->record($actor, $creating ? 'EMPLOYEE_CREATED' : 'EMPLOYEE_UPDATED', 'employee', $record->id, $old, $this->snapshot($record), 'employees');

                return $record;
            });
        } catch (Throwable $exception) {
            if ($newPath) {
                Storage::disk('local')->delete($newPath);
            }
            throw $exception;
        }
    }

    public function setStatus(User $actor, Employee $employee, EmployeeStatus $status): void
    {
        $this->authorize($actor, $employee);
        DB::transaction(function () use ($actor, $employee, $status) {
            $this->lockSchool();
            $this->authorize($actor, $employee);
            $record = Employee::whereKey($employee->id)->lockForUpdate()->firstOrFail();
            if ($record->status === $status) {
                return;
            }
            $old = $this->snapshot($record);
            $record->status = $status;
            $record->save();
            if ($status === EmployeeStatus::Inactive) {
                $profile = $record->teacherProfile()->lockForUpdate()->first();
                if ($profile && $profile->status === TeacherProfileStatus::Active) {
                    $before = $profile->only(TeacherProfile::AUDIT_FIELDS);
                    $profile->status = TeacherProfileStatus::Inactive;
                    $profile->save();
                    app(AuditLogger::class)->record($actor, 'TEACHER_PROFILE_DEACTIVATED', 'teacher_profile', $profile->id, $before, [...$profile->only(TeacherProfile::AUDIT_FIELDS), 'reason' => 'employee_inactive'], 'teachers');
                }
            }
            app(AuditLogger::class)->record($actor, $status === EmployeeStatus::Active ? 'EMPLOYEE_ACTIVATED' : 'EMPLOYEE_DEACTIVATED', 'employee', $record->id, $old, $this->snapshot($record), 'employees');
        });
    }
}
