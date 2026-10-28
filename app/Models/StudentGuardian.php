<?php

namespace App\Models;

use App\Enums\GuardianRelationship;
use App\Enums\GuardianStatus;
use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentGuardian extends Model
{
    use BelongsToSchool;

    protected $fillable = ['relationship', 'is_primary', 'is_emergency_contact'];

    protected $attributes = ['status' => 'ACTIVE', 'is_primary' => false, 'is_emergency_contact' => false];

    protected function casts(): array
    {
        return ['relationship' => GuardianRelationship::class, 'status' => GuardianStatus::class, 'is_primary' => 'boolean', 'is_emergency_contact' => 'boolean'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function guardian(): BelongsTo
    {
        return $this->belongsTo(Guardian::class);
    }
}
