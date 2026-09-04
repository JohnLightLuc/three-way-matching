<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Invoice;
use App\Models\MatchDecision;
use App\Models\PaymentAuthorization;
use App\Models\User;
use App\Services\ThreeWayMatchingService;
use Database\Seeders\DemoSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rejoue le jeu de démonstration (DemoSeeder) et vérifie que chaque facture
 * aboutit au statut annoncé par son « attendu moteur ». Garde-fou d'intégration
 * bout-en-bout schéma <-> cœur <-> service.
 */
final class DemoScenariosTest extends TestCase
{
    use RefreshDatabase;

    public function test_les_scenarios_de_demonstration_aboutissent_aux_statuts_attendus(): void
    {
        $this->seed([UserSeeder::class, DemoSeeder::class]);

        $service = app(ThreeWayMatchingService::class);
        $actor = User::where('email', 'clerk@demo.test')->firstOrFail();

        Invoice::with('lines')->get()->each(fn (Invoice $invoice) => $service->matchInvoice($invoice, $actor));

        $status = fn (string $ref): string => Invoice::where('reference', $ref)->value('status');

        $this->assertSame('matched', $status('INV-S01'));            // match complet
        $this->assertSame('partially_matched', $status('INV-S02')); // livraison partielle
        $this->assertSame('matched', $status('INV-S03-A'));         // FIFO multi-DN
        $this->assertSame('matched', $status('INV-S03-B'));
        $this->assertSame('needs_review', $status('INV-S04'));      // prix hors tolérance
        $this->assertSame('matched', $status('INV-S05'));           // prix dans la tolérance
        $this->assertSame('needs_review', $status('INV-S06'));      // sur-facturation
        $this->assertSame('matched', $status('INV-S07-A'));         // 1re facture
        $this->assertSame('needs_review', $status('INV-S07-B'));    // double facturation (F7)
        $this->assertSame('needs_review', $status('INV-S08'));      // fournisseur incohérent
        $this->assertSame('matched', $status('INV-S09'));           // sur-livraison plafonnée
        $this->assertSame('needs_review', $status('INV-S10'));      // 1 ligne hors PO
        $this->assertSame('submitted', $status('INV-S11'));         // rien reçu

        // S05 : autorisé au prix du PO (50 × 20,0000), pas au prix facturé.
        $s05 = MatchDecision::query()
            ->whereHas('invoiceLine.invoice', fn ($q) => $q->where('reference', 'INV-S05'))
            ->sole();
        $this->assertSame('1000.00', $s05->authorized_amount);

        // Le pool d'autorisations reste cohérent : une seule PA active par ligne autorisée.
        $this->assertSame(8, PaymentAuthorization::where('status', 'authorized')->count());
    }
}
