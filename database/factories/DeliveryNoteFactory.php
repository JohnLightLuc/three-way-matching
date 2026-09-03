<?php

namespace Database\Factories;

use App\Models\DeliveryNote;
use App\Models\PurchaseOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeliveryNote>
 */
class DeliveryNoteFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'reference' => 'DN-'.fake()->unique()->numerify('######'),
            'purchase_order_id' => PurchaseOrder::factory(),
            'received_at' => fake()->dateTimeBetween('-30 days', 'now')->format('Y-m-d'),
            'notes' => null,
        ];
    }

    /** Rattache le DN à un PO existant. */
    public function forPurchaseOrder(PurchaseOrder $po): static
    {
        return $this->state(['purchase_order_id' => $po->id]);
    }

    /** Fixe la date de réception (porte l'ordre FIFO — M10). */
    public function receivedOn(string $date): static
    {
        return $this->state(['received_at' => $date]);
    }
}
