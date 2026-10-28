<?php

namespace App\Models;

use App\Enums\SubjectStatus;
use App\Enums\SubjectType;
use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subject extends Model
{
    use BelongsToSchool, HasFactory;

    public const AUDIT_FIELDS = ['code', 'name', 'type', 'status'];

    protected $fillable = ['code', 'name', 'type'];

    protected $attributes = ['type' => 'CORE', 'status' => 'ACTIVE'];

    protected function casts(): array
    {
        return ['type' => SubjectType::class, 'status' => SubjectStatus::class];
    }

    public function classSubjects(): HasMany
    {
        return $this->hasMany(ClassSubject::class);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function examSubjects(): HasMany
    {
        return $this->hasMany(ExamSubject::class);
    }
}
