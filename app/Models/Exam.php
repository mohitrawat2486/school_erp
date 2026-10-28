<?php

namespace App\Models;

use App\Enums\ExamStatus;
use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Exam extends Model
{
    use BelongsToSchool;

    public const CONTEXT = ['academic_year_id', 'exam_term_id', 'class_id'];

    public const AUDIT_FIELDS = ['academic_year_id', 'exam_term_id', 'class_id', 'name', 'start_date', 'end_date', 'status'];

    protected $fillable = ['name', 'start_date', 'end_date'];

    protected $attributes = ['status' => 'DRAFT'];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date', 'status' => ExamStatus::class];
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class)->withTrashed();
    }

    public function examTerm(): BelongsTo
    {
        return $this->belongsTo(ExamTerm::class);
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function examSubjects(): HasMany
    {
        return $this->hasMany(ExamSubject::class);
    }

    public function studentMarks(): HasMany
    {
        return $this->hasMany(StudentMark::class);
    }

    public function resultBatches(): HasMany
    {
        return $this->hasMany(ResultBatch::class);
    }
}
