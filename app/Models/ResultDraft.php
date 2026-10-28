<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class ResultDraft extends Model
{
    use BelongsToSchool;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (ResultBatch::findOrFail($model->result_batch_id)->status !== 'GENERATED') {
                throw ValidationException::withMessages(['result' => 'Rows can only be added to a newly generated batch.']);
            }
        });
        static::updating(fn () => throw ValidationException::withMessages(['result' => 'Generated result rows cannot be edited. Return and regenerate the batch.']));
        static::deleting(fn () => throw ValidationException::withMessages(['result' => 'Generated result rows are retained as history.']));
    }

    public function resultBatch(): BelongsTo
    {
        return $this->belongsTo(ResultBatch::class);
    }
}
