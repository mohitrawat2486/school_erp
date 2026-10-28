<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class StudentFactory extends Factory
{
    public function definition(): array
    {
        return ['admission_number' => fake()->unique()->bothify('ADM-########'), 'first_name' => fake()->firstName(), 'last_name' => fake()->lastName(), 'admission_date' => '2020-04-01'];
    }
}
