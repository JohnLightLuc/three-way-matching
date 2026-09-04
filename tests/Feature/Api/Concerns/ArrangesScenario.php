<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Concerns;

use App\Models\DeliveryNote;
use App\Models\DeliveryNoteLine;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Supplier;
use App\Models\User;

/**
 * Fabrique rapidement l'état d'entrée d'un scénario de rapprochement (PO / DN /
 * facture) via les factories. Les tests « agissent » ensuite par HTTP.
 */
trait ArrangesScenario
{
    protected function user(bool $reviewer = false): User
    {
        return $reviewer ? User::factory()->reviewer()->create() : User::factory()->create();
    }

    protected function poLine(array $attrs = [], ?Supplier $supplier = null): PurchaseOrderLine
    {
        $po = PurchaseOrder::factory()
            ->forSupplier($supplier ?? Supplier::factory()->create())
            ->create();

        return PurchaseOrderLine::factory()->forPurchaseOrder($po)->create(array_merge([
            'line_no' => 1,
            'qty_ordered' => '100.000',
            'unit_price' => '10.0000',
        ], $attrs));
    }

    protected function deliver(PurchaseOrderLine $line, string $qty, string $receivedAt = '2026-08-20'): DeliveryNoteLine
    {
        $note = DeliveryNote::factory()
            ->forPurchaseOrder($line->purchaseOrder)
            ->receivedOn($receivedAt)
            ->create();

        return DeliveryNoteLine::factory()->forPoLine($line, $note)->qty($qty)->create();
    }

    /** Une facture mono-ligne rattachée à la ligne de PO. */
    protected function invoiceLineFor(
        PurchaseOrderLine $line,
        string $qtyInvoiced,
        ?string $unitPrice = null,
        ?Supplier $claimedSupplier = null,
    ): InvoiceLine {
        $factory = Invoice::factory()->forPurchaseOrder($line->purchaseOrder);

        if ($claimedSupplier !== null) {
            $factory = $factory->claimingSupplier($claimedSupplier);
        }

        $invoice = $factory->create();

        return InvoiceLine::factory()
            ->forPoLine($line, $qtyInvoiced)
            ->create([
                'invoice_id' => $invoice->id,
                'unit_price' => $unitPrice ?? $line->unit_price,
            ]);
    }

    protected function offPoInvoiceLine(PurchaseOrder $po, string $qty = '10.000', string $price = '5.0000'): InvoiceLine
    {
        $invoice = Invoice::factory()->forPurchaseOrder($po)->create();

        return InvoiceLine::factory()->offPo()->qty($qty)->create([
            'invoice_id' => $invoice->id,
            'article_code' => 'HORS-PO-X',
            'unit_price' => $price,
        ]);
    }
}
