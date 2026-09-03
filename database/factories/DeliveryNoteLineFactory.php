<?php

namespace Database\Factories;

use App\Models\DeliveryNote;
use App\Models\DeliveryNoteLine;
use App\Models\PurchaseOrderLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeliveryNoteLine>
 */
class DeliveryNoteLineFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'delivery_note_id' => DeliveryNote::factory(),
            'purchase_order_line_id' => PurchaseOrderLine::factory(),
            'qty_received' => fake()->numberBetween(1, 100),
        ];
    }

    /**
     * Rattache la ligne de DN à une ligne de PO existante, et — si aucun DN n'est
     * imposé — crée le DN sur le MÊME PO que la ligne (invariant M2 respecté).
     */
    public function forPoLine(PurchaseOrderLine $line, ?DeliveryNote $note = null): static
    {
        return $this->state([
            'purchase_order_line_id' => $line->id,
            'delivery_note_id' => $note?->id
                ?? DeliveryNote::factory()->forPurchaseOrder($line->purchaseOrder),
        ]);
    }

    /** Fixe la quantité reçue. */
    public function qty(int|float|string $qty): static
    {
        return $this->state(['qty_received' => $qty]);
    }
}
