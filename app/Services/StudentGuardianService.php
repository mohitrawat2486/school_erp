<?php

namespace App\Services;

use App\Enums\GuardianRelationship;
use App\Enums\GuardianStatus;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StudentGuardianService
{
    public const FIELDS = ['student_id', 'guardian_id', 'relationship', 'is_primary', 'is_emergency_contact', 'status'];

    private function lock(User $actor, Student $student, Guardian $guardian): void
    {
        app(GuardianService::class)->lock($actor, $guardian);
        Gate::forUser($actor->fresh())->authorize('update', $student);
        Student::whereKey($student->id)->lockForUpdate()->firstOrFail();
    }

    public function save(User $actor, Student $student, Guardian $guardian, array $data): StudentGuardian
    {
        $data = Validator::make(Arr::only($data, ['relationship', 'is_primary', 'is_emergency_contact']), ['relationship' => ['required', Rule::enum(GuardianRelationship::class)], 'is_primary' => ['required', 'boolean'], 'is_emergency_contact' => ['required', 'boolean']])->validate();

        return DB::transaction(function () use ($actor, $student, $guardian, $data) {
            $this->lock($actor, $student, $guardian);
            $guardian = Guardian::whereKey($guardian->id)->lockForUpdate()->firstOrFail();
            if ($guardian->status !== GuardianStatus::Active) {
                throw ValidationException::withMessages(['guardian' => 'Activate this guardian before linking or changing a contact.']);
            }
            $link = StudentGuardian::where('student_id', $student->id)->where('guardian_id', $guardian->id)->lockForUpdate()->first();
            if ($data['is_primary']) {
                $others = StudentGuardian::where('student_id', $student->id)->where('guardian_id', '!=', $guardian->id)->where('is_primary', true)->lockForUpdate()->get();
                foreach ($others as $other) {
                    $before = $other->only(self::FIELDS);
                    $other->is_primary = false;
                    $other->save();
                    $this->audit($actor, $other, $before, 'STUDENT_GUARDIAN_PRIMARY_REPLACED');
                }
            }
            $creating = ! $link;
            $link ??= new StudentGuardian;
            $before = $creating ? [] : $link->only(self::FIELDS);
            $link->student_id = $student->id;
            $link->guardian_id = $guardian->id;
            $link->fill($data);
            $link->status = GuardianStatus::Active;
            if (! $creating && ! $link->isDirty()) {
                return $link;
            }
            $link->save();
            $this->audit($actor, $link, $before, $creating ? 'STUDENT_GUARDIAN_LINKED' : 'STUDENT_GUARDIAN_UPDATED');

            return $link;
        });
    }

    public function unlink(User $actor, Student $student, Guardian $guardian): void
    {
        DB::transaction(function () use ($actor, $student, $guardian) {
            $this->lock($actor, $student, $guardian);
            $link = StudentGuardian::where('student_id', $student->id)->where('guardian_id', $guardian->id)->lockForUpdate()->firstOrFail();
            if ($link->status === GuardianStatus::Inactive) {
                return;
            }
            $before = $link->only(self::FIELDS);
            $link->status = GuardianStatus::Inactive;
            $link->is_primary = false;
            $link->save();
            $this->audit($actor, $link, $before, 'STUDENT_GUARDIAN_UNLINKED');
        });
    }

    private function audit(User $actor, StudentGuardian $link, array $before, string $action): void
    {
        app(AuditLogger::class)->record($actor,$action,'student_guardian',$link->id,$before,$link->only(self::FIELDS),'guardians');
    }
}
