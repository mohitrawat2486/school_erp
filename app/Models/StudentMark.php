<?php

namespace App\Models;

use App\Enums\StudentMarkStatus;
use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentMark extends Model
{
    use BelongsToSchool;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['theory_marks' => 'decimal:2', 'practical_marks' => 'decimal:2', 'total_marks' => 'decimal:2', 'is_absent' => 'boolean', 'status' => StudentMarkStatus::class, 'lock_version' => 'integer', 'submitted_at' => 'datetime', 'verified_at' => 'datetime', 'locked_at' => 'datetime'];
    }

    public function examSubject(): BelongsTo
    {
        return $this->belongsTo(ExamSubject::class);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(StudentEnrollment::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function lockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }
}
