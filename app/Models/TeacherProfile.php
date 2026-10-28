<?php

namespace App\Models;

use App\Enums\EmployeeStatus;
use App\Enums\TeacherProfileStatus;
use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TeacherProfile extends Model
{
    use BelongsToSchool, HasFactory;

    public const AUDIT_FIELDS = ['employee_id', 'qualification', 'specialization', 'experience', 'status'];

    protected $fillable = ['qualification', 'specialization', 'experience'];

    protected $attributes = ['status' => 'ACTIVE'];

    protected function casts(): array
    {
        return ['status' => TeacherProfileStatus::class, 'experience' => 'decimal:2'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function isEligible(): bool
    {
        return $this->status === TeacherProfileStatus::Active && $this->employee?->status === EmployeeStatus::Active;
    }

    public function scopeEligible(Builder $query): Builder
    {
        return $query->where('status', TeacherProfileStatus::Active->value)->whereHas('employee', fn (Builder $employee) => $employee->where('status', EmployeeStatus::Active->value));
    }

    public function teacherAssignments(): HasMany
    {
        return $this->hasMany(TeacherAssignment::class, 'teacher_id');
    }
}
