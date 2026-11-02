<?php

namespace App\Support;

use App\Models\Student;
use App\Rules\AadhaarNumber;
use Illuminate\Validation\Rule;

final class StudentRules
{
    public static function rules(?Student $student = null, bool $checkUnique = true): array
    {
        $admission = ['required', 'string', 'max:50'];
        if ($checkUnique) {
            $admission[] = Rule::unique('students', 'admission_number')->where('school_id', app(CurrentSchool::class)->id())->ignore($student?->id);
        }

        return [
            'admission_number' => $admission,
            'first_name' => ['required', 'string', 'max:100'], 'middle_name' => ['nullable', 'string', 'max:100'], 'last_name' => ['nullable', 'string', 'max:100'],
            'gender' => ['nullable', Rule::in(array_keys(Student::GENDERS))],
            'blood_group' => ['nullable', Rule::in(Student::BLOOD_GROUPS)],
            'aadhaar_number' => ['required', 'string', new AadhaarNumber],
            'date_of_birth' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today', 'before:admission_date'],
            'admission_date' => ['required', 'date_format:Y-m-d'], 'address' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
