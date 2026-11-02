<?php

namespace App\Services;

use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use App\Support\AdmissionGuardianRules;
use Illuminate\Support\Facades\Validator;

class AdmissionGuardian
{
    public function attach(User $actor, Student $student, array $data): void
    {
        $data = Validator::make($data, AdmissionGuardianRules::rules())->validate();
        $mode = $data['guardian_mode'] ?? 'none';
        if ($mode === 'none') {
            return;
        }

        if ($mode === 'new') {
            $details = $data['guardian'];
            if ($details['same_address']) {
                $details['address'] = $student->address;
            }
            $guardian = app(GuardianService::class)->save($actor, null, $details);
        } else {
            $guardian = Guardian::whereKey($data['guardian_id'])->firstOrFail();
        }

        app(StudentGuardianService::class)->save($actor, $student, $guardian, [
            'relationship' => $data['guardian_relationship'],
            'is_primary' => true,
            'is_emergency_contact' => $data['guardian_emergency'],
        ]);
    }
}
