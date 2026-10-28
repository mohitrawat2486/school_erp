<?php

namespace App\Http\Requests;

use App\Models\AcademicYear;
use App\Support\CurrentSchool;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveAcademicYearRequest extends FormRequest
{
    public function authorize(): bool
    {
        $year = $this->route('academic_year');

        return $year ? $this->user()->can('update', $year) : $this->user()->can('create', AcademicYear::class);
    }

    public function rules(): array
    {
        $unique = Rule::unique('academic_years', 'name')->where('school_id', app(CurrentSchool::class)->id());
        if ($year = $this->route('academic_year')) {
            $unique->ignore($year->id);
        }

        return [
            'name' => ['required', 'string', 'max:100', $unique],
            'start_date' => ['required', 'date_format:Y-m-d'], 'end_date' => ['required', 'date_format:Y-m-d', 'after:start_date'],
            'school_id' => ['prohibited'], 'status' => ['prohibited'], 'is_current' => ['prohibited'], 'current_school_id' => ['prohibited'], 'deleted_at' => ['prohibited'], 'id' => ['prohibited'],
        ];
    }
}
