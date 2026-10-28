<?php

namespace App\Services;

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\User;
use App\Support\CurrentSchool;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EmployeeAccountLinker
{
    public function link(User $actor, User $target, ?int $employeeId): void
    {
        DB::transaction(function () use ($actor, $target, $employeeId) {
            app(RbacGuard::class)->lockAndAuthorize($actor);
            $record = User::where('school_id', app(CurrentSchool::class)->id())->whereKey($target->id)->lockForUpdate()->firstOrFail();
            if ($employeeId !== null) {
                Employee::where('status', EmployeeStatus::Active->value)->whereKey($employeeId)->lockForUpdate()->firstOrFail();
                if (User::where('employee_id', $employeeId)->whereKeyNot($record->id)->exists()) {
                    throw ValidationException::withMessages(['employee_id' => 'This employee is already linked to another account.']);
                }
            }
            if ($record->employee_id === $employeeId) {
                return;
            }
            $old = ['employee_id' => $record->employee_id];
            $record->employee_id = $employeeId;
            $record->save();
            app(AuditLogger::class)->record($actor, $employeeId === null ? 'USER_EMPLOYEE_UNLINKED' : 'USER_EMPLOYEE_LINKED', 'user', $record->id, $old, ['employee_id' => $employeeId]);
        });
    }
}
