<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\Concerns\ArrangesScenario;
use Tests\TestCase;

final class InvoiceApiTest extends TestCase
{
    use ArrangesScenario;
    use RefreshDatabase;

    public function test_soumet_une_facture_rattachee_a_des_lignes_de_po(): void
    {
        $line = $this->poLine();

        $this->actingAs($this->user(), 'sanctum')
            ->postJson("/api/purchase-orders/{$line->purchase_order_id}/invoices", [
                'reference' => 'INV-001',
                'supplier_id' => $line->purchaseOrder->supplier_id,
                'invoice_date' => '2026-08-25',
                'lines' => [
                    ['purchase_order_line_id' => $line->id, 'article_code' => 'CIM-425', 'description' => 'Ciment', 'qty_invoiced' => '100.000', 'unit_price' => '10.0000'],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.lines.0.purchase_order_line_id', $line->id);

        $this->assertDatabaseHas('invoices', ['reference' => 'INV-001']);
    }

    public function test_accepte_une_ligne_hors_po(): void
    {
        $line = $this->poLine();

        $this->actingAs($this->user(), 'sanctum')
            ->postJson("/api/purchase-orders/{$line->purchase_order_id}/invoices", [
                'reference' => 'INV-002',
                'supplier_id' => $line->purchaseOrder->supplier_id,
                'invoice_date' => '2026-08-25',
                'lines' => [
                    ['purchase_order_line_id' => null, 'article_code' => 'ZZZ', 'description' => 'Hors PO', 'qty_invoiced' => '5.000', 'unit_price' => '3.0000'],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.lines.0.purchase_order_line_id', null);
    }

    public function test_reference_unique_par_fournisseur(): void
    {
        $line = $this->poLine();
        $supplierId = $line->purchaseOrder->supplier_id;
        Invoice::factory()->forPurchaseOrder($line->purchaseOrder)->create([
            'reference' => 'INV-DUP',
            'supplier_id' => $supplierId,
        ]);

        $this->actingAs($this->user(), 'sanctum')
            ->postJson("/api/purchase-orders/{$line->purchase_order_id}/invoices", [
                'reference' => 'INV-DUP',
                'supplier_id' => $supplierId,
                'invoice_date' => '2026-08-25',
                'lines' => [
                    ['purchase_order_line_id' => $line->id, 'article_code' => 'A', 'description' => 'd', 'qty_invoiced' => '1.000', 'unit_price' => '1.0000'],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('reference');
    }

    public function test_show_expose_l_etat_de_rapprochement(): void
    {
        $invoiceLine = $this->invoiceLineFor($this->poLine(), '100.000');

        $this->actingAs($this->user(), 'sanctum')
            ->getJson("/api/invoices/{$invoiceLine->invoice_id}")
            ->assertOk()
            ->assertJsonPath('data.lines.0.decision', null)
            ->assertJsonPath('data.lines.0.payment_authorization', null);
    }
}
