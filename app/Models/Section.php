<?php

namespace App\Models;

use App\Enums\ClassStatus;
use App\Enums\SectionStatus;
use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Section extends Model
{
    use BelongsToSchool, HasFactory;

    public const AUDIT_FIELDS = ['class_id', 'name', 'capacity', 'status'];

    protected $fillable = ['name', 'capacity'];

    protected $attributes = ['status' => 'ACTIVE'];

    protected function casts(): array
    {
        return ['capacity' => 'integer', 'status' => SectionStatus::class];
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function isEligible(): bool
    {
        return $this->status === SectionStatus::Active && $this->schoolClass?->status === ClassStatus::Active;
    }

    public function scopeEligible(Builder $query): Builder
    {
        return $query->where('status', SectionStatus::Active->value)->whereHas('schoolClass', fn (Builder $classes) => $classes->active());
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(StudentEnrollment::class, 'section_id');
    }
}
