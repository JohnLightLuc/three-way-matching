<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 *
 * Par défaut, supplier_id = fournisseur du PO (cas sain, F8 respecté). L'état
 * claimingSupplier() permet de simuler le cas « fournisseur incohérent ».
 */
class InvoiceFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'reference' => 'INV-'.fake()->unique()->numerify('######'),
            'purchase_order_id' => PurchaseOrder::factory(),
            'supplier_id' => fn (array $attrs) => PurchaseOrder::find($attrs['purchase_order_id'])?->supplier_id
                ?? Supplier::factory(),
            'invoice_date' => fake()->dateTimeBetween('-20 days', 'now')->format('Y-m-d'),
            'status' => 'submitted',
            'currency' => 'XOF',
            'notes' => null,
        ];
    }

    /** Rattache la facture à un PO existant (fournisseur revendiqué = celui du PO). */
    public function forPurchaseOrder(PurchaseOrder $po): static
    {
        return $this->state([
            'purchase_order_id' => $po->id,
            'supplier_id' => $po->supplier_id,
        ]);
    }

    /** Fournisseur revendiqué DIFFÉRENT de celui du PO (contrôle F8 -> needs_review). */
    public function claimingSupplier(Supplier $supplier): static
    {
        return $this->state(['supplier_id' => $supplier->id]);
    }
}
