<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class School extends Model
{
    use HasFactory;

    protected $attributes = ['is_active' => true, 'timezone' => 'Asia/Kolkata'];

    protected $fillable = ['code', 'name', 'timezone', 'is_active', 'short_name', 'affiliation_number', 'board', 'email', 'phone', 'alternate_phone', 'address_line_1', 'address_line_2', 'city', 'state', 'country', 'postal_code'];

    public function classes(): HasMany
    {
        return $this->hasMany(SchoolClass::class);
    }

    public function guardians(): HasMany
    {
        return $this->hasMany(Guardian::class);
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(StudentEnrollment::class, 'school_id');
    }

    public function examTerms(): HasMany
    {
        return $this->hasMany(ExamTerm::class, 'school_id');
    }

    public function exams(): HasMany
    {
        return $this->hasMany(Exam::class, 'school_id');
    }

    public function examSubjects(): HasMany
    {
        return $this->hasMany(ExamSubject::class);
    }
}
