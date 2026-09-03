<?php

namespace Database\Seeders;

use App\Models\DeliveryNote;
use App\Models\DeliveryNoteLine;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Supplier;
use Illuminate\Database\Seeder;

/**
 * Jeu de démonstration DÉTERMINISTE couvrant les 10 cas limites de CONCEPTION.md §1.4
 * + les 4 statuts MatchStatus. 1 scénario = 1 PO isolé (fournisseur + projet + PO
 * dédiés) pour la lisibilité.
 *
 * Le seeder s'arrête à l'ÉTAT D'ENTRÉE (PO / DN / factures). Il ne crée AUCUNE
 * ligne match_decisions / payment_authorizations : ce sont des sorties du moteur
 * (ThreeWayMatcher), produites aux étapes 3b/3c. La colonne « Attendu » ci-dessous
 * documente ce que le moteur devra conclure — elle n'est pas jouée ici.
 *
 * Références fixes (PO-S01, DN-S03-A, …), quantités et prix en dur : `migrate:fresh
 * --seed` est reproductible et exploitable tel quel par les tests de 3b/3c.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->s01_matchComplet();
        $this->s02_livraisonPartielle();
        $this->s03_facturationParLivraison();
        $this->s04_prixHorsTolerance();
        $this->s05_prixDansTolerance();
        $this->s06_surFacturation();
        $this->s07_doubleFacturation();
        $this->s08_fournisseurIncoherent();
        $this->s09_surLivraison();
        $this->s10_articleHorsPo();
        $this->s11_rienRecu();

        $this->summary();
    }

    /* --------------------------------------------------------------------- */
    /*  Scénarios                                                            */
    /* --------------------------------------------------------------------- */

    /** Match complet : commandé = livré = facturé, prix OK -> matched (autorise 1000.00). */
    private function s01_matchComplet(): void
    {
        $po = $this->po('S01', 'Match complet');
        $line = $this->line($po, 'CIM-425', 'Ciment CPA 42.5 - sac 50 kg', 'sac', '100.000', '10.0000');

        $this->receive($po, 'DN-S01', '2026-08-20', $line, '100.000');
        $this->bill($po, 'INV-S01', '2026-08-25', fn (Invoice $i) => InvoiceLine::factory()
            ->forPoLine($line, '100.000')->create(['invoice_id' => $i->id]));

        $this->expect('matched — autorise 100 × 10,0000 = 1000,00 (prix PO)');
    }

    /** Livraison partielle : 60 livrés sur 100 -> partially_matched (600.00), reste pending_receipt. */
    private function s02_livraisonPartielle(): void
    {
        $po = $this->po('S02', 'Livraison partielle');
        $line = $this->line($po, 'HA12', 'Fer a beton HA Ø12 - barre 12 m', 'barre', '100.000', '10.0000');

        $this->receive($po, 'DN-S02', '2026-08-21', $line, '60.000');
        $this->bill($po, 'INV-S02', '2026-08-25', fn (Invoice $i) => InvoiceLine::factory()
            ->forPoLine($line, '100.000')->create(['invoice_id' => $i->id]));

        $this->expect('partially_matched — autorise 60 × 10,0000 = 600,00 ; 40 en pending_receipt');
    }

    /** Facturation par livraison : 2 DN (FIFO par received_at) + 2 factures alignées. */
    private function s03_facturationParLivraison(): void
    {
        $po = $this->po('S03', 'Facturation par livraison (FIFO multi-DN)');
        $line = $this->line($po, 'GRAV-515', 'Gravier 5/15 - tonne', 't', '100.000', '5.0000');

        $this->receive($po, 'DN-S03-A', '2026-08-10', $line, '40.000'); // le plus ancien -> consommé en 1er
        $this->receive($po, 'DN-S03-B', '2026-08-24', $line, '35.000');

        $this->bill($po, 'INV-S03-A', '2026-08-12', fn (Invoice $i) => InvoiceLine::factory()
            ->forPoLine($line, '40.000')->create(['invoice_id' => $i->id]));
        $this->bill($po, 'INV-S03-B', '2026-08-26', fn (Invoice $i) => InvoiceLine::factory()
            ->forPoLine($line, '35.000')->create(['invoice_id' => $i->id]));

        $this->expect('2 décisions matched — INV-A consomme DN-A (40), INV-B consomme DN-B (35)');
    }

    /** Prix hors tolérance : +3 % > 1 % -> needs_review, autorise 0. */
    private function s04_prixHorsTolerance(): void
    {
        $po = $this->po('S04', 'Prix hors tolérance (+3 %)');
        $line = $this->line($po, 'PARP-20', 'Parpaing creux 20 - unite', 'u', '50.000', '20.0000');

        $this->receive($po, 'DN-S04', '2026-08-19', $line, '50.000');
        $this->bill($po, 'INV-S04', '2026-08-25', fn (Invoice $i) => InvoiceLine::factory()
            ->forPoLine($line, '50.000')->priceDeviation($line, 0.03) // 20,0000 -> 20,6000
            ->create(['invoice_id' => $i->id]));

        $this->expect('needs_review — prix facturé 20,6000 hors tolérance 1 % ; autorise 0');
    }

    /** Prix dans la tolérance : +0,75 % < 1 % -> matched, mais montant AU PRIX PO. */
    private function s05_prixDansTolerance(): void
    {
        $po = $this->po('S05', 'Prix dans la tolérance (+0,75 %)');
        $line = $this->line($po, 'SABLE-04', 'Sable 0/4 lave - m3', 'm3', '50.000', '20.0000');

        $this->receive($po, 'DN-S05', '2026-08-19', $line, '50.000');
        $this->bill($po, 'INV-S05', '2026-08-25', fn (Invoice $i) => InvoiceLine::factory()
            ->forPoLine($line, '50.000')->priceDeviation($line, 0.0075) // 20,0000 -> 20,1500
            ->create(['invoice_id' => $i->id]));

        $this->expect('matched — autorise 50 × 20,0000 = 1000,00 (prix PO, pas 1007,50)');
    }

    /** Sur-facturation : facturé 40 > commandé 30 -> needs_review, autorise 0. */
    private function s06_surFacturation(): void
    {
        $po = $this->po('S06', 'Sur-facturation (facturé > commandé)');
        $line = $this->line($po, 'TN-020', 'Tout-venant 0/20 - tonne', 't', '30.000', '12.0000');

        $this->receive($po, 'DN-S06', '2026-08-18', $line, '30.000');
        $this->bill($po, 'INV-S06', '2026-08-25', fn (Invoice $i) => InvoiceLine::factory()
            ->forPoLine($line, '40.000')->create(['invoice_id' => $i->id]));

        $this->expect('needs_review — 40 facturés > 30 commandés ; autorise 0');
    }

    /** Double facturation : 2 factures identiques pour un stock reçu une seule fois (F7). */
    private function s07_doubleFacturation(): void
    {
        $po = $this->po('S07', 'Double facturation (même stock reçu une fois)');
        $line = $this->line($po, 'CIM-325', 'Ciment CPJ 32.5 - sac 50 kg', 'sac', '100.000', '8.0000');

        $this->receive($po, 'DN-S07', '2026-08-18', $line, '100.000');
        $this->bill($po, 'INV-S07-A', '2026-08-25', fn (Invoice $i) => InvoiceLine::factory()
            ->forPoLine($line, '100.000')->create(['invoice_id' => $i->id]));
        $this->bill($po, 'INV-S07-B', '2026-08-26', fn (Invoice $i) => InvoiceLine::factory()
            ->forPoLine($line, '100.000')->create(['invoice_id' => $i->id]));

        $this->expect('INV-A matched (autorise 800,00) ; INV-B autorise 0 (stock déjà consommé)');
    }

    /** Fournisseur incohérent : facture revendique un autre fournisseur que le PO (F8). */
    private function s08_fournisseurIncoherent(): void
    {
        $po = $this->po('S08', 'Fournisseur incohérent');
        $line = $this->line($po, 'ENDUIT-MG', 'Enduit monocouche - sac 25 kg', 'sac', '20.000', '15.0000');

        $usurpateur = Supplier::factory()->create([
            'code' => 'SUP-S08-B',
            'name' => 'Fournisseur S08 (revendiqué, ≠ PO)',
        ]);

        $this->receive($po, 'DN-S08', '2026-08-18', $line, '20.000');
        $this->bill($po, 'INV-S08', '2026-08-25', fn (Invoice $i) => InvoiceLine::factory()
            ->forPoLine($line, '20.000')->create(['invoice_id' => $i->id]), claimant: $usurpateur);

        $this->expect('needs_review — fournisseur facture ≠ fournisseur PO ; autorise 0');
    }

    /** Sur-livraison : DN 30 pour une commande de 25 -> matched 25, les 5 en trop inertes. */
    private function s09_surLivraison(): void
    {
        $po = $this->po('S09', 'Sur-livraison (DN > commande)');
        $line = $this->line($po, 'BASTAING', 'Bastaing sapin 63x175 - m', 'm', '25.000', '40.0000');

        $this->receive($po, 'DN-S09', '2026-08-17', $line, '30.000'); // 5 de plus que commandé
        $this->bill($po, 'INV-S09', '2026-08-25', fn (Invoice $i) => InvoiceLine::factory()
            ->forPoLine($line, '25.000')->create(['invoice_id' => $i->id]));

        $this->expect('matched — autorise 25 × 40,0000 = 1000,00 ; 5 unités reçues jamais autorisables');
    }

    /** Article facturé absent du PO : 1 ligne conforme + 1 ligne hors PO (FK nulle). */
    private function s10_articleHorsPo(): void
    {
        $po = $this->po('S10', 'Article facturé absent du PO');
        $line = $this->line($po, 'PARP-15', 'Parpaing creux 15 - unite', 'u', '200.000', '1.5000');

        $this->receive($po, 'DN-S10', '2026-08-16', $line, '200.000');
        $this->bill($po, 'INV-S10', '2026-08-25', function (Invoice $i) use ($line): void {
            InvoiceLine::factory()->forPoLine($line, '200.000')->create(['invoice_id' => $i->id]);
            InvoiceLine::factory()->offPo()->qty('10.000')->create([
                'invoice_id' => $i->id,
                'article_code' => 'HORS-PO-ACIER',
                'description' => 'Treillis soude (non commandé)',
                'unit_price' => '5.0000',
            ]);
        });

        $this->expect('ligne PARP-15 matched (300,00) ; ligne HORS-PO-ACIER needs_review, autorise 0');
    }

    /** Rien reçu : PO + facture, aucun DN -> pending_receipt, autorise 0. */
    private function s11_rienRecu(): void
    {
        $po = $this->po('S11', 'Rien reçu encore');
        $line = $this->line($po, 'ETAI-3M', 'Etai etayage 3 m - unite', 'u', '40.000', '15.0000');

        $this->bill($po, 'INV-S11', '2026-08-25', fn (Invoice $i) => InvoiceLine::factory()
            ->forPoLine($line, '40.000')->create(['invoice_id' => $i->id]));

        $this->expect('pending_receipt — aucun DN ; autorise 0');
    }

    /* --------------------------------------------------------------------- */
    /*  Helpers                                                              */
    /* --------------------------------------------------------------------- */

    /** Fournisseur + projet + PO dédiés au scénario. */
    private function po(string $key, string $label): PurchaseOrder
    {
        $this->command->getOutput()->writeln("  <fg=cyan>{$key}</> — {$label}");

        $supplier = Supplier::factory()->create(['code' => "SUP-{$key}", 'name' => "Fournisseur {$key}"]);
        $project = Project::factory()->create(['code' => "PRJ-{$key}", 'name' => "Chantier {$key}"]);

        return PurchaseOrder::factory()
            ->forSupplier($supplier)
            ->forProject($project)
            ->create(['reference' => "PO-{$key}"]);
    }

    private function line(
        PurchaseOrder $po,
        string $article,
        string $description,
        string $unit,
        string $qtyOrdered,
        string $unitPrice,
        int $lineNo = 1,
    ): PurchaseOrderLine {
        return PurchaseOrderLine::factory()->forPurchaseOrder($po)->create([
            'line_no' => $lineNo,
            'article_code' => $article,
            'description' => $description,
            'unit' => $unit,
            'qty_ordered' => $qtyOrdered,
            'unit_price' => $unitPrice,
        ]);
    }

    /** Un DN mono-ligne rattaché à $line, reçu le $date (porte l'ordre FIFO). */
    private function receive(
        PurchaseOrder $po,
        string $reference,
        string $date,
        PurchaseOrderLine $line,
        string $qtyReceived,
    ): void {
        $note = DeliveryNote::factory()
            ->forPurchaseOrder($po)
            ->receivedOn($date)
            ->create(['reference' => $reference]);

        DeliveryNoteLine::factory()->forPoLine($line, $note)->qty($qtyReceived)->create();
    }

    /**
     * Une facture rattachée au PO ; $lines reçoit l'Invoice et crée ses lignes.
     * $claimant (optionnel) = fournisseur revendiqué différent de celui du PO.
     */
    private function bill(
        PurchaseOrder $po,
        string $reference,
        string $date,
        callable $lines,
        ?Supplier $claimant = null,
    ): void {
        $factory = Invoice::factory()->forPurchaseOrder($po);

        if ($claimant !== null) {
            $factory = $factory->claimingSupplier($claimant);
        }

        $invoice = $factory->create(['reference' => $reference, 'invoice_date' => $date]);

        $lines($invoice);
    }

    private function expect(string $outcome): void
    {
        $this->command->getOutput()->writeln("     <fg=gray>↳ attendu moteur : {$outcome}</>");
    }

    private function summary(): void
    {
        $out = $this->command->getOutput();
        $out->writeln('');

        $this->command->table(
            ['Table', 'Lignes'],
            collect([
                'suppliers' => Supplier::count(),
                'projects' => Project::count(),
                'purchase_orders' => PurchaseOrder::count(),
                'purchase_order_lines' => PurchaseOrderLine::count(),
                'delivery_notes' => DeliveryNote::count(),
                'delivery_note_lines' => DeliveryNoteLine::count(),
                'invoices' => Invoice::count(),
                'invoice_lines' => InvoiceLine::count(),
            ])->map(fn (int $count, string $table) => [$table, $count])->values()->all(),
        );

        $out->writeln('  <fg=green>match_decisions / payment_authorizations : 0 (sorties moteur — étapes 3b/3c)</>');
    }
}
