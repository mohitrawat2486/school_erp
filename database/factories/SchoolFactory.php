<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class SchoolFactory extends Factory
{
    public function definition(): array
    {
        return ['code' => fake()->unique()->bothify('SCH-########'), 'name' => fake()->company().' School'];
    }
}
