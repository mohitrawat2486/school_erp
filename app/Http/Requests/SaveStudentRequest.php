<?php

namespace App\Http\Requests;

use App\Models\Guardian;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Support\AdmissionGuardianRules;
use App\Support\GuardianEditorRules;
use App\Support\RasterImageRules;
use App\Support\StudentDepartureRules;
use App\Support\StudentEnrollmentRules;
use App\Support\StudentRules;
use Illuminate\Foundation\Http\FormRequest;

class SaveStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $student = $this->route('student');

        $allowed = $student ? $this->user()->can('update', $student) : $this->user()->can('create', Student::class);
        if ((! $student && in_array($this->input('guardian_mode'), ['new', 'existing'], true)) || $this->filled('guardians_present')) {
            $allowed = $allowed && $this->user()->can('create', Guardian::class);
        }
        if ($this->input('enrollment.action') === 'save' || in_array($this->input('departure.action'), ['WITHDRAW', 'TRANSFER'], true)) {
            $allowed = $allowed && $this->user()->can('create', StudentEnrollment::class);
        }

        return $allowed;
    }

    public function rules(): array
    {
        return StudentRules::rules($this->route('student')) + [
            'photo' => RasterImageRules::rules(), 'school_id' => ['prohibited'], 'status' => ['prohibited'], 'photo_path' => ['prohibited'], 'id' => ['prohibited'], 'user_id' => ['prohibited'], 'class_id' => ['prohibited'], 'section_id' => ['prohibited'], 'roll_number' => ['prohibited'],
        ] + AdmissionGuardianRules::rules(! $this->route('student')) + GuardianEditorRules::rules($this->all(), $this->route('student')) + StudentEnrollmentRules::rules($this->all()) + StudentDepartureRules::rules($this->all());
    }

    protected function prepareForValidation(): void
    {
        if (in_array($this->input('guardians_present'), [1, '1'], true) && ! $this->has('guardians')) {
            $this->merge(['guardians' => []]);
        }
    }

    public function attributes(): array
    {
        return ['guardian.first_name' => 'guardian first name', 'guardian.last_name' => 'guardian last name', 'guardian.phone' => 'guardian phone', 'guardian.alternate_phone' => 'guardian alternate phone', 'guardian.email' => 'guardian email', 'guardian.address' => 'guardian address', 'guardian_id' => 'existing guardian', 'guardian_relationship' => 'relationship to student', 'guardian_emergency' => 'emergency contact setting'];
    }
}
