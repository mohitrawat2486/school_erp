<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class ResultBatch extends Model
{
    use BelongsToSchool;

    public const AUDIT_FIELDS = ['id', 'exam_id', 'revision', 'status', 'lock_version', 'source_hash', 'payload_hash', 'generated_by', 'generated_at', 'approved_by', 'approved_at', 'published_by', 'published_at', 'returned_by', 'returned_at', 'return_reason'];

    protected $guarded = ['*'];

    protected $attributes = ['status' => 'GENERATED', 'lock_version' => 1];

    protected $hidden = ['active_exam_id'];

    protected function casts(): array
    {
        return ['calculation_context' => 'array', 'revision' => 'integer', 'lock_version' => 'integer', 'generated_at' => 'datetime', 'approved_at' => 'datetime', 'published_at' => 'datetime', 'returned_at' => 'datetime', 'snapshots_completed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function ($m) {
            if ($m->getOriginal('status') === 'PUBLISHED') {
                throw ValidationException::withMessages(['batch' => 'Published batches are immutable.']);
            }
        });
        static::deleting(fn () => throw ValidationException::withMessages(['batch' => 'Result batches are retained as history.']));
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function gradingScheme(): BelongsTo
    {
        return $this->belongsTo(GradingScheme::class);
    }

    public function drafts(): HasMany
    {
        return $this->hasMany(ResultDraft::class);
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function returnedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by');
    }

    public function studentResults(): HasMany
    {
        return $this->hasMany(StudentResult::class);
    }
}
