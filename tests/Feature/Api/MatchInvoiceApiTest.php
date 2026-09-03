<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\MatchDecision;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Api\Concerns\ArrangesScenario;
use Tests\TestCase;

final class MatchInvoiceApiTest extends TestCase
{
    use ArrangesScenario;
    use RefreshDatabase;

    private function match(int $invoiceId): TestResponse
    {
        return $this->actingAs($this->user(), 'sanctum')->postJson("/api/invoices/{$invoiceId}/match");
    }

    public function test_match_complet_autorise_au_prix_du_po(): void
    {
        $line = $this->poLine(['qty_ordered' => '100.000', 'unit_price' => '10.0000']);
        $dnl = $this->deliver($line, '100.000');
        $invLine = $this->invoiceLineFor($line, '100.000');

        $this->match($invLine->invoice_id)
            ->assertOk()
            ->assertJsonPath('data.status', 'matched')
            ->assertJsonPath('data.lines.0.decision.status', 'matched')
            ->assertJsonPath('data.lines.0.decision.authorized_amount', '1000.00')
            ->assertJsonPath('data.lines.0.payment_authorization.status', 'authorized');

        $this->assertDatabaseHas('match_decisions', [
            'invoice_line_id' => $invLine->id,
            'status' => 'matched',
            'authorized_qty' => '100.000',
            'authorized_amount' => '1000.00',
            'actor_type' => 'system',
        ]);
        $this->assertDatabaseHas('match_decision_consumptions', [
            'delivery_note_line_id' => $dnl->id,
            'qty_consumed' => '100.000',
        ]);
        $this->assertDatabaseHas('payment_authorizations', [
            'invoice_line_id' => $invLine->id,
            'authorized_amount' => '1000.00',
            'status' => 'authorized',
        ]);
    }

    public function test_livraison_partielle_autorise_la_portion_livree(): void
    {
        $line = $this->poLine(['qty_ordered' => '100.000', 'unit_price' => '10.0000']);
        $this->deliver($line, '60.000');
        $invLine = $this->invoiceLineFor($line, '100.000');

        $this->match($invLine->invoice_id)
            ->assertJsonPath('data.status', 'partially_matched')
            ->assertJsonPath('data.lines.0.decision.status', 'partially_matched')
            ->assertJsonPath('data.lines.0.decision.authorized_amount', '600.00');
    }

    public function test_prix_hors_tolerance_passe_en_needs_review_sans_autorisation(): void
    {
        $line = $this->poLine(['qty_ordered' => '50.000', 'unit_price' => '20.0000']);
        $this->deliver($line, '50.000');
        $invLine = $this->invoiceLineFor($line, '50.000', unitPrice: '20.6000'); // +3 %

        $this->match($invLine->invoice_id)
            ->assertJsonPath('data.status', 'needs_review')
            ->assertJsonPath('data.lines.0.decision.status', 'needs_review')
            ->assertJsonPath('data.lines.0.payment_authorization', null);

        $this->assertDatabaseCount('payment_authorizations', 0);
    }

    public function test_sur_facturation_passe_en_needs_review(): void
    {
        $line = $this->poLine(['qty_ordered' => '30.000', 'unit_price' => '12.0000']);
        $this->deliver($line, '30.000');
        $invLine = $this->invoiceLineFor($line, '40.000');

        $this->match($invLine->invoice_id)->assertJsonPath('data.lines.0.decision.status', 'needs_review');

        $this->assertContains('over_invoiced', collect(MatchDecision::sole()->reasons)->pluck('code')->all());
    }

    public function test_fournisseur_incoherent_passe_en_needs_review(): void
    {
        $line = $this->poLine();
        $this->deliver($line, '100.000');
        $invLine = $this->invoiceLineFor($line, '100.000', claimedSupplier: Supplier::factory()->create());

        $this->match($invLine->invoice_id)->assertJsonPath('data.lines.0.decision.status', 'needs_review');
        $this->assertContains('supplier_mismatch', collect(MatchDecision::sole()->reasons)->pluck('code')->all());
    }

    public function test_article_hors_po_passe_en_needs_review(): void
    {
        $line = $this->poLine();
        $invLine = $this->offPoInvoiceLine($line->purchaseOrder);

        $this->match($invLine->invoice_id)->assertJsonPath('data.lines.0.decision.status', 'needs_review');

        $this->assertDatabaseHas('match_decisions', [
            'invoice_line_id' => $invLine->id,
            'purchase_order_line_id' => null,
            'status' => 'needs_review',
        ]);
    }

    public function test_fifo_consomme_le_dn_le_plus_ancien_d_abord(): void
    {
        $line = $this->poLine(['qty_ordered' => '100.000', 'unit_price' => '5.0000']);
        $recent = $this->deliver($line, '50.000', '2026-08-24');
        $old = $this->deliver($line, '30.000', '2026-08-10');
        $invLine = $this->invoiceLineFor($line, '60.000');

        $this->match($invLine->invoice_id)->assertJsonPath('data.lines.0.decision.status', 'matched');

        $consumptions = MatchDecision::sole()->consumptions()->orderBy('id')->get();
        $this->assertSame($old->id, $consumptions[0]->delivery_note_line_id);
        $this->assertSame('30.000', $consumptions[0]->qty_consumed);
        $this->assertSame($recent->id, $consumptions[1]->delivery_note_line_id);
        $this->assertSame('30.000', $consumptions[1]->qty_consumed);
    }

    public function test_rejouer_le_rapprochement_est_idempotent(): void
    {
        $line = $this->poLine();
        $this->deliver($line, '100.000');
        $invLine = $this->invoiceLineFor($line, '100.000');

        $this->match($invLine->invoice_id)->assertOk();
        $this->assertDatabaseCount('match_decisions', 1);

        $this->match($invLine->invoice_id)->assertOk();
        $this->assertDatabaseCount('match_decisions', 1); // aucune nouvelle ligne
        $this->assertDatabaseCount('payment_authorizations', 1);
    }

    public function test_un_nouvel_arrivage_cree_une_decision_qui_supersede_l_ancienne(): void
    {
        $line = $this->poLine(['qty_ordered' => '100.000', 'unit_price' => '10.0000']);
        $this->deliver($line, '60.000', '2026-08-20');
        $invLine = $this->invoiceLineFor($line, '100.000');

        $this->match($invLine->invoice_id)->assertJsonPath('data.lines.0.decision.status', 'partially_matched');
        $first = MatchDecision::sole();

        // Un DN complémentaire arrive.
        $this->deliver($line, '40.000', '2026-09-01');
        $this->match($invLine->invoice_id)->assertJsonPath('data.lines.0.decision.status', 'matched');

        $this->assertDatabaseCount('match_decisions', 2);
        $second = MatchDecision::whereKeyNot($first->id)->sole();
        $this->assertSame($first->id, $second->supersedes_id);
        $this->assertSame('100.000', $second->authorized_qty);

        // L'ancienne autorisation est révoquée, une nouvelle est active.
        $this->assertDatabaseHas('payment_authorizations', ['match_decision_id' => $first->id, 'status' => 'revoked']);
        $this->assertDatabaseHas('payment_authorizations', ['match_decision_id' => $second->id, 'status' => 'authorized']);
    }

    public function test_double_facturation_de_la_meme_reception_bloque_la_seconde(): void
    {
        $line = $this->poLine(['qty_ordered' => '100.000', 'unit_price' => '8.0000']);
        $this->deliver($line, '100.000');

        $invA = $this->invoiceLineFor($line, '100.000');
        $invB = $this->invoiceLineFor($line, '100.000');

        $this->match($invA->invoice_id)->assertJsonPath('data.lines.0.decision.status', 'matched');
        $this->match($invB->invoice_id)->assertJsonPath('data.lines.0.decision.status', 'needs_review');

        $decisionB = MatchDecision::where('invoice_line_id', $invB->id)->sole();
        $this->assertSame('0.000', $decisionB->authorized_qty);
        $this->assertContains('over_invoiced', collect($decisionB->reasons)->pluck('code')->all());
    }
}
