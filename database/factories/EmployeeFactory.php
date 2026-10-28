<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class EmployeeFactory extends Factory
{
    public function definition(): array
    {
        return ['employee_number' => fake()->unique()->bothify('EMP-########'), 'first_name' => fake()->firstName(), 'last_name' => fake()->lastName(), 'joining_date' => '2020-04-01', 'employee_type' => 'OTHER'];
    }
}
