<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\Concerns\ArrangesScenario;
use Tests\TestCase;

final class DeliveryNoteApiTest extends TestCase
{
    use ArrangesScenario;
    use RefreshDatabase;

    public function test_enregistre_un_bon_de_livraison(): void
    {
        $line = $this->poLine();

        $this->actingAs($this->user(), 'sanctum')
            ->postJson("/api/purchase-orders/{$line->purchase_order_id}/delivery-notes", [
                'reference' => 'DN-001',
                'received_at' => '2026-08-20',
                'lines' => [
                    ['purchase_order_line_id' => $line->id, 'qty_received' => '60.000'],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.reference', 'DN-001')
            ->assertJsonPath('data.lines.0.qty_received', '60.000');

        $this->assertDatabaseHas('delivery_note_lines', [
            'purchase_order_line_id' => $line->id,
            'qty_received' => '60.000',
        ]);
    }

    public function test_rejette_une_ligne_pointant_une_ligne_de_po_d_un_autre_po(): void
    {
        $lineA = $this->poLine();
        $lineB = $this->poLine(); // autre PO

        $this->actingAs($this->user(), 'sanctum')
            ->postJson("/api/purchase-orders/{$lineA->purchase_order_id}/delivery-notes", [
                'reference' => 'DN-002',
                'received_at' => '2026-08-20',
                'lines' => [
                    ['purchase_order_line_id' => $lineB->id, 'qty_received' => '10.000'],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('lines.0.purchase_order_line_id');
    }
}
