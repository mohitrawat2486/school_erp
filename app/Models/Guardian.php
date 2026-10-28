<?php

namespace App\Models;

use App\Enums\GuardianStatus;
use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Guardian extends Model
{
    use BelongsToSchool, HasFactory;

    protected $fillable = ['first_name', 'last_name', 'phone', 'alternate_phone', 'email', 'occupation', 'address'];

    protected $attributes = ['status' => 'ACTIVE'];

    protected function casts(): array
    {
        return ['status' => GuardianStatus::class];
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function studentLinks(): HasMany
    {
        return $this->hasMany(StudentGuardian::class);
    }
}
