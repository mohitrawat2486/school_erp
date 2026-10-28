<?php

namespace Database\Factories;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

class TeacherProfileFactory extends Factory
{
    public function definition(): array
    {
        return ['employee_id' => Employee::factory(), 'qualification' => 'B.Ed.', 'specialization' => 'Mathematics', 'experience' => '3.50'];
    }
}
