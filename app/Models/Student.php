<?php

namespace App\Models;

use App\Enums\StudentStatus;
use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Student extends Model
{
    use BelongsToSchool, HasFactory;

    public const BLOOD_GROUPS = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

    public const GENDERS = ['MALE' => 'Male', 'FEMALE' => 'Female', 'OTHER' => 'Other', 'PREFER_NOT_TO_SAY' => 'Prefer not to say'];

    protected $fillable = ['admission_number', 'first_name', 'middle_name', 'last_name', 'gender', 'date_of_birth', 'admission_date', 'blood_group', 'address'];

    protected $attributes = ['status' => 'ACTIVE'];

    protected function casts(): array
    {
        return ['date_of_birth' => 'date', 'admission_date' => 'date', 'status' => StudentStatus::class];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function guardianLinks(): HasMany
    {
        return $this->hasMany(StudentGuardian::class);
    }

    public function fullName(): string
    {
        return implode(' ', array_filter([$this->first_name, $this->middle_name, $this->last_name], fn ($part) => $part !== null && $part !== ''));
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(StudentEnrollment::class, 'student_id');
    }

    public function studentMarks(): HasMany
    {
        return $this->hasMany(StudentMark::class);
    }
}
