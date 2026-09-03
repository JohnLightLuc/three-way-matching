<?php

namespace Database\Factories;

use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'code' => 'PRJ-'.fake()->unique()->numerify('#####'),
            'name' => 'Chantier '.fake()->city(),
        ];
    }
}
