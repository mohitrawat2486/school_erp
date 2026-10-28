<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class StudentResult extends Model
{
    use BelongsToSchool;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['report_context' => 'array', 'total_max_marks' => 'decimal:2', 'obtained_marks' => 'decimal:2', 'percentage' => 'decimal:2', 'grade_point' => 'decimal:2', 'grade_min_percentage' => 'decimal:2', 'grade_max_percentage' => 'decimal:2', 'published_at' => 'datetime', 'version' => 'integer', 'rank' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw ValidationException::withMessages(['snapshot' => 'Published snapshots cannot be changed.']));
        static::deleting(fn () => throw ValidationException::withMessages(['snapshot' => 'Published snapshots cannot be deleted.']));
    }

    public function resultBatch(): BelongsTo
    {
        return $this->belongsTo(ResultBatch::class);
    }

    public function subjects(): HasMany
    {
        return $this->hasMany(StudentResultSubject::class);
    }

    public function summary(): array
    {
        return $this->only(['student_name', 'section_name', 'roll_number', 'obtained_marks', 'total_max_marks', 'percentage', 'grade', 'grade_point', 'result_status', 'rank']);
    }
}
