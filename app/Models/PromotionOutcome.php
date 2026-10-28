<?php

namespace App\Models;

use App\Enums\PromotionAction;
use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromotionOutcome extends Model
{
    use BelongsToSchool;

    protected $guarded = ['id', 'school_id'];

    protected function casts(): array
    {
        return ['action' => PromotionAction::class];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(PromotionBatch::class, 'promotion_batch_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function sourceEnrollment(): BelongsTo
    {
        return $this->belongsTo(StudentEnrollment::class, 'source_enrollment_id');
    }

    public function targetEnrollment(): BelongsTo
    {
        return $this->belongsTo(StudentEnrollment::class, 'target_enrollment_id');
    }
}
