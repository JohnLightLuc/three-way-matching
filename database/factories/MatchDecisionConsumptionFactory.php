<?php

namespace Database\Factories;

use App\Models\DeliveryNoteLine;
use App\Models\MatchDecision;
use App\Models\MatchDecisionConsumption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MatchDecisionConsumption>
 *
 * Trace FIFO « décision ↔ ligne de DN » (M9/M10). Fabriquée par le moteur (3b/3c).
 */
class MatchDecisionConsumptionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'match_decision_id' => MatchDecision::factory(),
            'delivery_note_line_id' => DeliveryNoteLine::factory(),
            'qty_consumed' => fake()->numberBetween(1, 50),
        ];
    }
}
