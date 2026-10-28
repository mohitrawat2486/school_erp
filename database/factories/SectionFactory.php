<?php

namespace Database\Factories;

use App\Models\SchoolClass;
use Illuminate\Database\Eloquent\Factories\Factory;

class SectionFactory extends Factory
{
    public function definition(): array
    {
        return ['class_id' => SchoolClass::factory(), 'name' => 'A', 'capacity' => 40];
    }
}
