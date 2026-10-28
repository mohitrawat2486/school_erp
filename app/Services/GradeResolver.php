<?php

namespace App\Services;

use App\Models\GradeRule;
use App\Models\GradingScheme;
use App\Models\School;
use App\Support\CurrentSchool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class GradeResolver
{
    public function resolve(GradingScheme $scheme, mixed $percentage): GradeRule
    {
        abort_unless((int) $scheme->school_id === app(CurrentSchool::class)->id(), 403);
        $value = Validator::make(['percentage' => $percentage], ['percentage' => 'required|numeric|decimal:0,2|between:0,100'])->validate()['percentage'];

        return DB::transaction(function () use ($scheme, $value) {
            School::whereKey(app(CurrentSchool::class)->id())->lockForUpdate()->firstOrFail();
            $scheme = GradingScheme::findOrFail($scheme->id);
            if ($scheme->status->value !== 'ACTIVE') {
                throw ValidationException::withMessages(['percentage' => 'Only active grading schemes can resolve grades.']);
            }
            $matches = $scheme->rules()->where('min_percentage', '<=', $value)->where('max_percentage', '>=', $value)->limit(2)->get();
            if ($matches->count() !== 1) {
                throw ValidationException::withMessages(['percentage' => 'This scheme has an incomplete or ambiguous grade configuration.']);
            }

            return $matches->first();
        });
    }
}
