<?php

namespace App\Models;

use App\Enums\ExamTermStatus;
use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExamTerm extends Model
{
    use BelongsToSchool;

    public const AUDIT_FIELDS = ['academic_year_id', 'name', 'status'];

    protected $fillable = ['name'];

    protected $attributes = ['status' => 'ACTIVE'];

    protected function casts(): array
    {
        return ['status' => ExamTermStatus::class];
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class)->withTrashed();
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function exams(): HasMany
    {
        return $this->hasMany(Exam::class);
    }
}
