<?php

namespace Database\Factories;

use App\Models\InvoiceLine;
use App\Models\MatchDecision;
use App\Models\PaymentAuthorization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentAuthorization>
 *
 * Registre d'allocation courant (M5). Fabriqué par le service à partir d'une
 * décision autorisée (3b/3c).
 */
class PaymentAuthorizationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'invoice_line_id' => InvoiceLine::factory(),
            'match_decision_id' => MatchDecision::factory(),
            'authorized_qty' => 0,
            'authorized_amount' => 0,
            'status' => 'authorized',
        ];
    }

    /** Allocation libérée (décision remplacée — M10). */
    public function revoked(): static
    {
        return $this->state(['status' => 'revoked']);
    }
}
