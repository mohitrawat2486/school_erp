<?php

namespace App\Models;

use App\Enums\EnrollmentStatus;
use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class StudentEnrollment extends Model
{
    use BelongsToSchool;

    public const CONTEXT = ['student_id', 'academic_year_id', 'class_id', 'section_id'];

    public const AUDIT_FIELDS = ['student_id', 'academic_year_id', 'class_id', 'section_id', 'roll_number', 'joined_at', 'status'];

    protected $fillable = ['roll_number', 'joined_at'];

    protected $attributes = ['status' => 'ACTIVE'];

    protected function casts(): array
    {
        return ['roll_number' => 'integer', 'joined_at' => 'date', 'status' => EnrollmentStatus::class];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class)->withTrashed();
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function promotionOutcome(): HasOne
    {
        return $this->hasOne(PromotionOutcome::class, 'source_enrollment_id');
    }

    public function promotionOrigin(): HasOne
    {
        return $this->hasOne(PromotionOutcome::class, 'target_enrollment_id');
    }

    public function studentMarks(): HasMany
    {
        return $this->hasMany(StudentMark::class, 'enrollment_id');
    }
}
