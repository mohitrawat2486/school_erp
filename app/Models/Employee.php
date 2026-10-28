<?php

namespace App\Models;

use App\Enums\EmployeeStatus;
use App\Enums\EmployeeType;
use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Employee extends Model
{
    use BelongsToSchool, HasFactory;

    public const GENDERS = ['MALE' => 'Male', 'FEMALE' => 'Female', 'OTHER' => 'Other', 'PREFER_NOT_TO_SAY' => 'Prefer not to say'];

    protected $fillable = ['employee_number', 'first_name', 'middle_name', 'last_name', 'employee_type', 'email', 'phone', 'alternate_phone', 'gender', 'date_of_birth', 'joining_date', 'designation', 'department', 'address'];

    protected $attributes = ['status' => 'ACTIVE', 'employee_type' => 'OTHER'];

    protected function casts(): array
    {
        return ['date_of_birth' => 'date', 'joining_date' => 'date', 'employee_type' => EmployeeType::class, 'status' => EmployeeStatus::class];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function teacherProfile(): HasOne
    {
        return $this->hasOne(TeacherProfile::class);
    }

    public function fullName(): string
    {
        return implode(' ', array_filter([$this->first_name, $this->middle_name, $this->last_name], fn ($part) => $part !== null && $part !== ''));
    }

    public function account(): HasOne
    {
        return $this->hasOne(User::class, 'employee_id');
    }
}
