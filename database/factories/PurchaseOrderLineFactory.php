<?php

namespace Database\Factories;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseOrderLine>
 *
 * line_no par défaut = 1 ; le seeder le fixe explicitement quand un PO porte
 * plusieurs lignes (UNIQUE(purchase_order_id, line_no)).
 */
class PurchaseOrderLineFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'purchase_order_id' => PurchaseOrder::factory(),
            'line_no' => 1,
            'article_code' => 'ART-'.fake()->unique()->numerify('#####'),
            'description' => fake()->randomElement([
                'Ciment CPA 42.5 – sac 50 kg',
                'Fer à béton HA Ø12 – barre 12 m',
                'Gravier 5/15 – tonne',
                'Parpaing creux 20 – unité',
                'Sable 0/4 lavé – m³',
            ]),
            'unit' => fake()->randomElement(['sac', 'barre', 't', 'u', 'm3']),
            'qty_ordered' => fake()->numberBetween(10, 500),
            'unit_price' => fake()->randomFloat(4, 1, 100),
        ];
    }

    /** Rattache la ligne à un PO existant. */
    public function forPurchaseOrder(PurchaseOrder $po): static
    {
        return $this->state(['purchase_order_id' => $po->id]);
    }
}
