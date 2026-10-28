<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PromotionBatch extends Model
{
    use BelongsToSchool;

    protected $guarded = ['id', 'school_id'];

    protected $hidden = ['operation_key', 'request_hash'];

    protected function casts(): array
    {
        return ['joined_at' => 'date'];
    }

    public function outcomes(): HasMany
    {
        return $this->hasMany(PromotionOutcome::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function sourceYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, 'source_academic_year_id')->withTrashed();
    }

    public function targetYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, 'target_academic_year_id')->withTrashed();
    }

    public function sourceClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'source_class_id');
    }

    public function targetClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'target_class_id');
    }

    public function sourceSection(): BelongsTo
    {
        return $this->belongsTo(Section::class, 'source_section_id');
    }

    public function targetSection(): BelongsTo
    {
        return $this->belongsTo(Section::class, 'target_section_id');
    }
}
