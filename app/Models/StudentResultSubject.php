<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class StudentResultSubject extends Model
{
    use BelongsToSchool;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return array_fill_keys(['theory_marks', 'practical_marks', 'theory_max_marks', 'practical_max_marks', 'passing_marks', 'max_marks', 'obtained_marks', 'percentage', 'grade_point', 'grade_min_percentage', 'grade_max_percentage'], 'decimal:2') + ['is_absent' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw ValidationException::withMessages(['snapshot' => 'Published subjects cannot be changed.']));
        static::deleting(fn () => throw ValidationException::withMessages(['snapshot' => 'Published subjects cannot be deleted.']));
    }

    public function studentResult(): BelongsTo
    {
        return $this->belongsTo(StudentResult::class);
    }
}
