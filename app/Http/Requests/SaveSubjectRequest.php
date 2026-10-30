<?php

namespace App\Http\Requests;

use App\Enums\SubjectType;
use App\Models\Subject;
use App\Support\CurrentSchool;
use App\Support\SubjectWorkspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveSubjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        $subject = $this->route('subject');

        return $subject ? $this->user()->can('update', $subject) : $this->user()->can('create', Subject::class);
    }

    public function rules(): array
    {
        $unique = Rule::unique('subjects', 'code')->where('school_id', app(CurrentSchool::class)->id());
        if ($subject = $this->route('subject')) {
            $unique->ignore($subject->id);
        }

        return ['name' => ['required', 'string', 'max:100'], 'code' => ['required', 'string', 'max:50', 'alpha_dash', $unique], 'type' => ['required', Rule::enum(SubjectType::class)], 'school_id' => ['prohibited'], 'id' => ['prohibited'], 'status' => ['prohibited']] + SubjectWorkspace::rules();
    }
}
