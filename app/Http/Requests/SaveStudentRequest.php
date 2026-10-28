<?php

namespace App\Http\Requests;

use App\Models\Student;
use App\Support\CurrentSchool;
use App\Support\RasterImageRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $student = $this->route('student');

        return $student ? $this->user()->can('update', $student) : $this->user()->can('create', Student::class);
    }

    public function rules(): array
    {
        $unique = Rule::unique('students', 'admission_number')->where('school_id', app(CurrentSchool::class)->id());
        if ($student = $this->route('student')) {
            $unique->ignore($student->id);
        }

        return [
            'admission_number' => ['required', 'string', 'max:50', $unique],
            'first_name' => ['required', 'string', 'max:100'], 'middle_name' => ['nullable', 'string', 'max:100'], 'last_name' => ['nullable', 'string', 'max:100'],
            'gender' => ['nullable', Rule::in(array_keys(Student::GENDERS))],
            'blood_group' => ['nullable', Rule::in(Student::BLOOD_GROUPS)],
            'date_of_birth' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today', 'before:admission_date'],
            'admission_date' => ['required', 'date_format:Y-m-d'], 'address' => ['nullable', 'string', 'max:2000'],
            'photo' => RasterImageRules::rules(), 'school_id' => ['prohibited'], 'status' => ['prohibited'], 'photo_path' => ['prohibited'], 'id' => ['prohibited'], 'user_id' => ['prohibited'], 'class_id' => ['prohibited'], 'section_id' => ['prohibited'], 'roll_number' => ['prohibited'],
        ];
    }
}
