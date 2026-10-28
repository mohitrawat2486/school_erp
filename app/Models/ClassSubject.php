<?php

namespace App\Models;

use App\Enums\AcademicYearStatus;
use App\Enums\ClassStatus;
use App\Enums\ClassSubjectStatus;
use App\Enums\SubjectStatus;
use App\Models\Concerns\BelongsToSchool;
use App\Support\MarksConfiguration;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClassSubject extends Model
{
    use BelongsToSchool, HasFactory;

    public const AUDIT_FIELDS = ['academic_year_id', 'class_id', 'subject_id', 'theory_max_marks', 'practical_max_marks', 'passing_marks', 'status'];

    protected $fillable = ['theory_max_marks', 'practical_max_marks', 'passing_marks'];

    protected $attributes = ['status' => 'ACTIVE'];

    protected function casts(): array
    {
        return ['status' => ClassSubjectStatus::class, 'theory_max_marks' => 'decimal:2', 'practical_max_marks' => 'decimal:2', 'passing_marks' => 'decimal:2'];
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function maximumMarks(): ?string
    {
        return MarksConfiguration::total($this->only(MarksConfiguration::FIELDS));
    }

    public function isEditable(): bool
    {
        return in_array($this->academicYear?->status, [AcademicYearStatus::Draft, AcademicYearStatus::Active], true);
    }

    public function isEligible(): bool
    {
        return $this->status === ClassSubjectStatus::Active && $this->academicYear?->status === AcademicYearStatus::Active && $this->schoolClass?->status === ClassStatus::Active && $this->subject?->status === SubjectStatus::Active;
    }

    public function scopeEligible(Builder $query): Builder
    {
        return $query->where('status', ClassSubjectStatus::Active->value)->whereHas('academicYear', fn ($q) => $q->where('status', AcademicYearStatus::Active->value))->whereHas('schoolClass', fn ($q) => $q->active())->whereHas('subject', fn ($q) => $q->where('status', SubjectStatus::Active->value));
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function examSubjects(): HasMany
    {
        return $this->hasMany(ExamSubject::class);
    }
}
