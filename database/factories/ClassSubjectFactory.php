<?php

namespace Database\Factories;

use App\Models\AcademicYear;
use App\Models\SchoolClass;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Factories\Factory;

class ClassSubjectFactory extends Factory
{
    public function definition(): array
    {
        return ['academic_year_id' => fn () => AcademicYear::create(['name' => fake()->unique()->bothify('Year-########'), 'start_date' => '2026-04-01', 'end_date' => '2027-03-31'])->id, 'class_id' => SchoolClass::factory(), 'subject_id' => Subject::factory(), 'theory_max_marks' => '100.00', 'passing_marks' => '33.00'];
    }
}
