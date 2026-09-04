<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\MatchDecision;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\Concerns\ArrangesScenario;
use Tests\TestCase;

final class DecisionHistoryApiTest extends TestCase
{
    use ArrangesScenario;
    use RefreshDatabase;

    public function test_l_historique_expose_la_chaine_supersedes_la_plus_recente_d_abord(): void
    {
        // Fournisseur incohérent -> needs_review, puis un réviseur approuve.
        $line = $this->poLine(['qty_ordered' => '50.000', 'unit_price' => '20.0000']);
        $this->deliver($line, '50.000');
        $invLine = $this->invoiceLineFor($line, '50.000', claimedSupplier: Supplier::factory()->create());

        $reviewer = $this->user(reviewer: true);
        $this->actingAs($reviewer, 'sanctum')->postJson("/api/invoices/{$invLine->invoice_id}/match")->assertOk();
        $systemDecision = MatchDecision::sole();
        $this->actingAs($reviewer, 'sanctum')
            ->postJson("/api/match-decisions/{$systemDecision->id}/review", ['action' => 'approve'])
            ->assertCreated();

        $response = $this->actingAs($reviewer, 'sanctum')
            ->getJson("/api/invoice-lines/{$invLine->id}/decisions")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        // La plus récente (l'approbation) en tête, marquée courante, avec son auteur.
        $response->assertJsonPath('data.0.actor_type', 'user')
            ->assertJsonPath('data.0.is_current', true)
            ->assertJsonPath('data.0.actor_user.id', $reviewer->id)
            ->assertJsonPath('data.0.supersedes_id', $systemDecision->id);

        // L'ancienne décision système, désormais superseded.
        $response->assertJsonPath('data.1.id', $systemDecision->id)
            ->assertJsonPath('data.1.actor_type', 'system')
            ->assertJsonPath('data.1.is_current', false)
            ->assertJsonPath('data.1.actor_user', null);
    }
}
