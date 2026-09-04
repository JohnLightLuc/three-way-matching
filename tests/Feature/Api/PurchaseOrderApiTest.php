<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\Concerns\ArrangesScenario;
use Tests\TestCase;

final class PurchaseOrderApiTest extends TestCase
{
    use ArrangesScenario;
    use RefreshDatabase;

    public function test_cree_un_po_avec_ses_lignes(): void
    {
        $supplier = Supplier::factory()->create();
        $project = Project::factory()->create();

        $response = $this->actingAs($this->user(), 'sanctum')->postJson('/api/purchase-orders', [
            'reference' => 'PO-2026-001',
            'supplier_id' => $supplier->id,
            'project_id' => $project->id,
            'lines' => [
                ['article_code' => 'CIM-425', 'description' => 'Ciment', 'unit' => 'sac', 'qty_ordered' => '100.000', 'unit_price' => '10.0000'],
                ['article_code' => 'HA12', 'description' => 'Fer', 'unit' => 'barre', 'qty_ordered' => '50.000', 'unit_price' => '7.5000'],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.reference', 'PO-2026-001')
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.currency', 'XOF')
            ->assertJsonCount(2, 'data.lines')
            ->assertJsonPath('data.lines.0.line_no', 1)   // auto-attribué
            ->assertJsonPath('data.lines.1.line_no', 2);

        $this->assertDatabaseHas('purchase_orders', ['reference' => 'PO-2026-001']);
        $this->assertDatabaseCount('purchase_order_lines', 2);
    }

    public function test_refuse_un_po_sans_ligne(): void
    {
        $this->actingAs($this->user(), 'sanctum')->postJson('/api/purchase-orders', [
            'reference' => 'PO-X',
            'supplier_id' => Supplier::factory()->create()->id,
            'project_id' => Project::factory()->create()->id,
            'lines' => [],
        ])->assertStatus(422)->assertJsonValidationErrorFor('lines');
    }

    public function test_refuse_une_quantite_nulle_ou_negative(): void
    {
        $this->actingAs($this->user(), 'sanctum')->postJson('/api/purchase-orders', [
            'reference' => 'PO-Y',
            'supplier_id' => Supplier::factory()->create()->id,
            'project_id' => Project::factory()->create()->id,
            'lines' => [
                ['article_code' => 'A', 'description' => 'd', 'unit' => 'u', 'qty_ordered' => '0', 'unit_price' => '1.0000'],
            ],
        ])->assertStatus(422)->assertJsonValidationErrorFor('lines.0.qty_ordered');
    }

    public function test_refuse_une_reference_deja_prise(): void
    {
        $po = PurchaseOrder::factory()->create(['reference' => 'PO-DUP']);

        $this->actingAs($this->user(), 'sanctum')->postJson('/api/purchase-orders', [
            'reference' => 'PO-DUP',
            'supplier_id' => $po->supplier_id,
            'project_id' => $po->project_id,
            'lines' => [
                ['article_code' => 'A', 'description' => 'd', 'unit' => 'u', 'qty_ordered' => '1', 'unit_price' => '1.0000'],
            ],
        ])->assertStatus(422)->assertJsonValidationErrorFor('reference');
    }

    public function test_show_expose_les_agregations_des_lignes(): void
    {
        $line = $this->poLine(['qty_ordered' => '100.000']);
        $this->deliver($line, '60.000');

        $this->actingAs($this->user(), 'sanctum')
            ->getJson("/api/purchase-orders/{$line->purchase_order_id}")
            ->assertOk()
            ->assertJsonPath('data.lines.0.qty_received', '60.000')
            ->assertJsonPath('data.lines.0.qty_matched', '0.000')
            ->assertJsonPath('data.lines.0.qty_available', '100.000');
    }

    public function test_liste_paginee_et_filtrable_par_statut(): void
    {
        PurchaseOrder::factory()->count(3)->create(['status' => 'open']);
        PurchaseOrder::factory()->create(['status' => 'closed']);

        $this->actingAs($this->user(), 'sanctum')
            ->getJson('/api/purchase-orders')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'reference', 'status', 'supplier', 'project', 'lines_count']], 'meta' => ['total']])
            ->assertJsonPath('meta.total', 4);

        $this->actingAs($this->user(), 'sanctum')
            ->getJson('/api/purchase-orders?status=closed')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_route_protegee(): void
    {
        $this->postJson('/api/purchase-orders', [])->assertUnauthorized();
        $this->getJson('/api/purchase-orders')->assertUnauthorized();
    }
}
