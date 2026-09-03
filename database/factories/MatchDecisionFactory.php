<?php

namespace Database\Factories;

use App\Models\InvoiceLine;
use App\Models\MatchDecision;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MatchDecision>
 *
 * Utilisée surtout par les tests des étapes 3b/3c. Le seeder de démo 3a ne
 * fabrique PAS de décisions : ce sont des sorties du moteur.
 */
class MatchDecisionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'invoice_line_id' => InvoiceLine::factory(),
            'purchase_order_line_id' => fn (array $attrs) => InvoiceLine::find($attrs['invoice_line_id'])?->purchase_order_line_id,
            'status' => 'matched',
            'matchable_qty' => 0,
            'authorized_qty' => 0,
            'authorized_amount' => 0,
            'price_delta_pct' => null,
            'reasons' => [],
            'actor_type' => 'system',
            'actor_user_id' => null,
            'decided_at' => now(),
            'inputs_snapshot' => [],
            'supersedes_id' => null,
        ];
    }

    /** Décision produite par le moteur. */
    public function system(): static
    {
        return $this->state(['actor_type' => 'system', 'actor_user_id' => null]);
    }

    /** Décision produite par une révision humaine (F10). */
    public function byUser(User $user): static
    {
        return $this->state(['actor_type' => 'user', 'actor_user_id' => $user->id]);
    }

    /** Écart signalé : rien n'est autorisé (règles 4 & 6). */
    public function needsReview(): static
    {
        return $this->state([
            'status' => 'needs_review',
            'authorized_qty' => 0,
            'authorized_amount' => 0,
        ]);
    }

    /** Marque cette décision comme remplaçant une décision antérieure. */
    public function supersedes(MatchDecision $previous): static
    {
        return $this->state([
            'supersedes_id' => $previous->id,
            'invoice_line_id' => $previous->invoice_line_id,
            'purchase_order_line_id' => $previous->purchase_order_line_id,
        ]);
    }
}
