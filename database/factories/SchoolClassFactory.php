<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class SchoolClassFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => 'Class '.fake()->unique()->numerify('########'), 'code' => fake()->unique()->bothify('CLS-########'), 'sort_order' => 0];
    }
}
