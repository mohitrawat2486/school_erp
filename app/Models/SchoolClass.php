<?php

namespace App\Models;

use App\Enums\ClassStatus;
use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SchoolClass extends Model
{
    use BelongsToSchool, HasFactory;

    protected $table = 'classes';

    public const AUDIT_FIELDS = ['name', 'code', 'sort_order', 'status'];

    protected $fillable = ['name', 'code', 'sort_order'];

    protected $attributes = ['sort_order' => 0, 'status' => 'ACTIVE'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'status' => ClassStatus::class];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(Section::class, 'class_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', ClassStatus::Active->value);
    }

    public function classSubjects(): HasMany
    {
        return $this->hasMany(ClassSubject::class, 'class_id');
    }

    public function teacherAssignments(): HasMany
    {
        return $this->hasMany(TeacherAssignment::class, 'class_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(StudentEnrollment::class, 'class_id');
    }

    public function exams(): HasMany
    {
        return $this->hasMany(Exam::class, 'class_id');
    }
}
