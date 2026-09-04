<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\MatchDecision;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\Concerns\ArrangesScenario;
use Tests\TestCase;

final class ReviewApiTest extends TestCase
{
    use ArrangesScenario;
    use RefreshDatabase;

    /** Crée une décision needs_review (fournisseur incohérent) et la renvoie. */
    private function needsReviewDecision(): MatchDecision
    {
        $line = $this->poLine(['qty_ordered' => '50.000', 'unit_price' => '20.0000']);
        $this->deliver($line, '50.000');
        $invLine = $this->invoiceLineFor($line, '50.000', claimedSupplier: Supplier::factory()->create());

        $this->actingAs($this->user(), 'sanctum')->postJson("/api/invoices/{$invLine->invoice_id}/match")->assertOk();

        return MatchDecision::sole();
    }

    public function test_un_non_reviewer_ne_peut_pas_reviser(): void
    {
        $decision = $this->needsReviewDecision();

        $this->actingAs($this->user(), 'sanctum')
            ->postJson("/api/match-decisions/{$decision->id}/review", ['action' => 'reject'])
            ->assertForbidden();
    }

    public function test_approve_cree_une_decision_user_et_autorise_le_paiement(): void
    {
        $decision = $this->needsReviewDecision();

        $this->actingAs($this->user(reviewer: true), 'sanctum')
            ->postJson("/api/match-decisions/{$decision->id}/review", ['action' => 'approve'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'matched')
            ->assertJsonPath('data.actor_type', 'user')
            ->assertJsonPath('data.supersedes_id', $decision->id);

        $new = MatchDecision::whereKeyNot($decision->id)->sole();
        $this->assertSame('1000.00', $new->authorized_amount); // 50 × 20 (prix PO)
        $this->assertContains('review_approved', collect($new->reasons)->pluck('code')->all());
        $this->assertDatabaseHas('payment_authorizations', [
            'match_decision_id' => $new->id,
            'status' => 'authorized',
        ]);

        // La file de revue ne le montre plus.
        $this->actingAs($this->user(reviewer: true), 'sanctum')
            ->getJson('/api/match-decisions?status=needs_review')
            ->assertJsonCount(0, 'data');
    }

    public function test_reject_laisse_la_ligne_non_payee_et_sort_de_la_file(): void
    {
        $decision = $this->needsReviewDecision();

        $this->actingAs($this->user(reviewer: true), 'sanctum')
            ->postJson("/api/match-decisions/{$decision->id}/review", ['action' => 'reject'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'needs_review')
            ->assertJsonPath('data.actor_type', 'user')
            ->assertJsonPath('data.authorized_qty', '0.000');

        $this->assertDatabaseCount('payment_authorizations', 0);

        $this->actingAs($this->user(reviewer: true), 'sanctum')
            ->getJson('/api/match-decisions?status=needs_review')
            ->assertJsonCount(0, 'data');
    }

    public function test_on_ne_peut_pas_reviser_une_decision_qui_n_est_pas_en_needs_review(): void
    {
        $line = $this->poLine();
        $this->deliver($line, '100.000');
        $invLine = $this->invoiceLineFor($line, '100.000');
        $this->actingAs($this->user(), 'sanctum')->postJson("/api/invoices/{$invLine->invoice_id}/match")->assertOk();

        $matched = MatchDecision::sole();

        $this->actingAs($this->user(reviewer: true), 'sanctum')
            ->postJson("/api/match-decisions/{$matched->id}/review", ['action' => 'reject'])
            ->assertStatus(422);
    }

    public function test_on_ne_peut_pas_reviser_une_decision_deja_remplacee(): void
    {
        $decision = $this->needsReviewDecision();

        // Première révision : reject -> decision superseded.
        $this->actingAs($this->user(reviewer: true), 'sanctum')
            ->postJson("/api/match-decisions/{$decision->id}/review", ['action' => 'reject'])
            ->assertCreated();

        // Deuxième révision de la MÊME décision (désormais superseded) -> 409.
        $this->actingAs($this->user(reviewer: true), 'sanctum')
            ->postJson("/api/match-decisions/{$decision->id}/review", ['action' => 'approve'])
            ->assertStatus(409);
    }
}
