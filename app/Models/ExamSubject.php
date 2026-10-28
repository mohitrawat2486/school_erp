<?php

namespace App\Models;

use App\Enums\ExamSubjectStatus;
use App\Models\Concerns\BelongsToSchool;
use App\Support\MarksConfiguration;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExamSubject extends Model
{
    use BelongsToSchool;

    public const AUDIT_FIELDS = ['exam_id', 'class_subject_id', 'academic_year_id', 'class_id', 'subject_id', 'theory_max_marks', 'practical_max_marks', 'passing_marks', 'status'];

    protected $fillable = ['theory_max_marks', 'practical_max_marks', 'passing_marks'];

    protected $attributes = ['status' => 'ACTIVE'];

    protected function casts(): array
    {
        return ['status' => ExamSubjectStatus::class, 'theory_max_marks' => 'decimal:2', 'practical_max_marks' => 'decimal:2', 'passing_marks' => 'decimal:2'];
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function classSubject(): BelongsTo
    {
        return $this->belongsTo(ClassSubject::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function maximumMarks(): ?string
    {
        return MarksConfiguration::total($this->only(MarksConfiguration::FIELDS));
    }

    public function studentMarks(): HasMany
    {
        return $this->hasMany(StudentMark::class);
    }
}
