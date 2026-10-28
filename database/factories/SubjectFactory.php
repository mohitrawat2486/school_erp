<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class SubjectFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => 'Subject '.fake()->unique()->numerify('########'), 'code' => fake()->unique()->bothify('SUB-########'), 'type' => 'CORE'];
    }
}
