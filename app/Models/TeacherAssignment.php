<?php

namespace App\Models;

use App\Enums\AcademicYearStatus;
use App\Enums\AssignmentType;
use App\Enums\TeacherAssignmentStatus;
use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeacherAssignment extends Model
{
    use BelongsToSchool, HasFactory;

    public const IDENTITY = ['academic_year_id', 'teacher_id', 'class_id', 'section_id', 'subject_id', 'assignment_type'];

    public const AUDIT_FIELDS = ['academic_year_id', 'teacher_id', 'class_id', 'section_id', 'subject_id', 'class_subject_id', 'assignment_type', 'status'];

    protected $guarded = ['*'];

    protected $attributes = ['status' => 'ACTIVE'];

    protected $hidden = ['section_key', 'subject_key', 'active_class_teacher_section'];

    protected function casts(): array
    {
        return ['status' => TeacherAssignmentStatus::class, 'assignment_type' => AssignmentType::class];
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(TeacherProfile::class, 'teacher_id');
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function classSubject(): BelongsTo
    {
        return $this->belongsTo(ClassSubject::class);
    }

    public function scopeEffective(Builder $query): Builder
    {
        return $query->where('status', TeacherAssignmentStatus::Active->value)
            ->whereHas('academicYear', fn ($q) => $q->where('status', AcademicYearStatus::Active->value))
            ->whereHas('teacher', fn ($q) => $q->eligible())->whereHas('schoolClass', fn ($q) => $q->active())
            ->where(fn ($q) => $q->whereNull('section_id')->orWhereHas('section', fn ($s) => $s->eligible()))
            ->where(fn ($q) => $q->whereNull('class_subject_id')->orWhereHas('classSubject', fn ($s) => $s->eligible()));
    }

    public function isEditable(): bool
    {
        return in_array($this->academicYear?->status, [AcademicYearStatus::Draft, AcademicYearStatus::Active], true);
    }
}
