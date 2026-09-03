<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Matching;

use App\Domain\Matching\ConsumedReceipt;
use App\Domain\Matching\InvoiceLineData;
use App\Domain\Matching\MatchInput;
use App\Domain\Matching\MatchReason;
use App\Domain\Matching\MatchStatus;
use App\Domain\Matching\OrderLineData;
use App\Domain\Matching\ReceiptData;
use App\Domain\Matching\ThreeWayMatcher;
use App\Domain\Matching\Tolerances;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Cœur métier testé EN ISOLATION (règle 8) : pas de RefreshDatabase, pas de
 * TestCase Laravel, aucune connexion base. Couvre les 10 cas limites de
 * CONCEPTION.md §1.4 + les 4 statuts + l'arithmétique décimale + la FIFO.
 */
final class ThreeWayMatcherTest extends TestCase
{
    private const PO_SUPPLIER = 7;

    private ThreeWayMatcher $matcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->matcher = new ThreeWayMatcher;
    }

    /* ------------------------------------------------------------------ */
    /*  matched */
    /* ------------------------------------------------------------------ */

    public function test_match_complet_autorise_tout_au_prix_du_po(): void
    {
        $result = $this->matcher->evaluate($this->input(
            orderLine: new OrderLineData(1, '100.000', '10.0000', 'CIM-425'),
            invoiceLine: new InvoiceLineData(10, '100.000', '10.0000', 'CIM-425'),
            receipts: [$this->receipt(50, '2026-08-20', '100.000')],
        ));

        $this->assertSame(MatchStatus::Matched, $result->status);
        $this->assertSame('100.000', (string) $result->authorizedQty);
        $this->assertSame('1000.00', (string) $result->authorizedAmount);
        $this->assertSame([], $result->reasonCodes());
        $this->assertTrue($result->authorizesPayment());
        $this->assertConsumes($result->consumedReceipts, [50 => '100.000']);
    }

    public function test_prix_dans_la_tolerance_reste_matched_mais_montant_au_prix_po(): void
    {
        // +0,75 % (< 1 %) : prix facturé 20,1500 vs prix PO 20,0000.
        $result = $this->matcher->evaluate($this->input(
            orderLine: new OrderLineData(2, '50.000', '20.0000', 'SABLE-04'),
            invoiceLine: new InvoiceLineData(11, '50.000', '20.1500', 'SABLE-04'),
            receipts: [$this->receipt(51, '2026-08-19', '50.000')],
        ));

        $this->assertSame(MatchStatus::Matched, $result->status);
        $this->assertSame('50.000', (string) $result->authorizedQty);
        // 50 × 20,0000 (prix PO) = 1000,00 — surtout PAS 50 × 20,1500 = 1007,50.
        $this->assertSame('1000.00', (string) $result->authorizedAmount);
        $this->assertSame('0.7500', (string) $result->priceDeltaPct);
        $this->assertSame([], $result->reasonCodes());
    }

    public function test_sur_livraison_est_plafonnee_par_la_commande_sans_anomalie(): void
    {
        // Commandé 25, reçu 30 (5 de trop), facturé 25.
        $result = $this->matcher->evaluate($this->input(
            orderLine: new OrderLineData(3, '25.000', '40.0000', 'BASTAING'),
            invoiceLine: new InvoiceLineData(12, '25.000', '40.0000', 'BASTAING'),
            receipts: [$this->receipt(52, '2026-08-17', '30.000')],
        ));

        $this->assertSame(MatchStatus::Matched, $result->status);
        $this->assertSame('25.000', (string) $result->authorizedQty);
        $this->assertSame('1000.00', (string) $result->authorizedAmount);
        $this->assertFalse($result->hasAnomaly());
        // Seules 25 des 30 unités reçues sont imputées.
        $this->assertConsumes($result->consumedReceipts, [52 => '25.000']);
    }

    /* ------------------------------------------------------------------ */
    /*  partially_matched / pending_receipt */
    /* ------------------------------------------------------------------ */

    public function test_livraison_partielle_autorise_la_portion_livree(): void
    {
        $result = $this->matcher->evaluate($this->input(
            orderLine: new OrderLineData(4, '100.000', '10.0000', 'HA12'),
            invoiceLine: new InvoiceLineData(13, '100.000', '10.0000', 'HA12'),
            receipts: [$this->receipt(53, '2026-08-21', '60.000')],
        ));

        $this->assertSame(MatchStatus::PartiallyMatched, $result->status);
        $this->assertSame('60.000', (string) $result->authorizedQty);
        $this->assertSame('600.00', (string) $result->authorizedAmount);
        $this->assertSame([MatchReason::PartialReceipt->value], $result->reasonCodes());
        $this->assertTrue($result->authorizesPayment());
        $this->assertConsumes($result->consumedReceipts, [53 => '60.000']);
    }

    public function test_rien_recu_donne_pending_receipt_sans_autorisation(): void
    {
        $result = $this->matcher->evaluate($this->input(
            orderLine: new OrderLineData(5, '40.000', '15.0000', 'ETAI-3M'),
            invoiceLine: new InvoiceLineData(14, '40.000', '15.0000', 'ETAI-3M'),
            receipts: [],
        ));

        $this->assertSame(MatchStatus::PendingReceipt, $result->status);
        $this->assertSame('0.000', (string) $result->authorizedQty);
        $this->assertSame('0.00', (string) $result->authorizedAmount);
        $this->assertSame([MatchReason::NothingReceived->value], $result->reasonCodes());
        $this->assertFalse($result->authorizesPayment());
        $this->assertSame([], $result->consumedReceipts);
    }

    public function test_stock_entierement_consomme_ailleurs_donne_pending_receipt(): void
    {
        // 100 reçus mais déjà tous imputés par des décisions autorisées.
        $result = $this->matcher->evaluate($this->input(
            orderLine: new OrderLineData(6, '100.000', '8.0000', 'CIM-325'),
            invoiceLine: new InvoiceLineData(15, '50.000', '8.0000', 'CIM-325'),
            qtyAlreadyMatched: '50', // 50 encore disponibles sur la commande...
            receipts: [$this->receipt(54, '2026-08-18', '100.000', consumed: '100.000')], // ...mais 0 en stock
        ));

        $this->assertSame(MatchStatus::PendingReceipt, $result->status);
        $this->assertSame('0.000', (string) $result->authorizedQty);
        $this->assertSame([MatchReason::NothingReceived->value], $result->reasonCodes());
    }

    /* ------------------------------------------------------------------ */
    /*  needs_review : anomalies */
    /* ------------------------------------------------------------------ */

    public function test_prix_hors_tolerance_passe_en_revue_et_autorise_zero(): void
    {
        // +3 % (> 1 %).
        $result = $this->matcher->evaluate($this->input(
            orderLine: new OrderLineData(7, '50.000', '20.0000', 'PARP-20'),
            invoiceLine: new InvoiceLineData(16, '50.000', '20.6000', 'PARP-20'),
            receipts: [$this->receipt(55, '2026-08-19', '50.000')],
        ));

        $this->assertSame(MatchStatus::NeedsReview, $result->status);
        $this->assertSame('0.000', (string) $result->authorizedQty);
        $this->assertSame('0.00', (string) $result->authorizedAmount);
        $this->assertSame('3.0000', (string) $result->priceDeltaPct);
        $this->assertTrue($result->hasReason(MatchReason::PriceOutOfTolerance));
        $this->assertFalse($result->authorizesPayment());
        $this->assertSame([], $result->consumedReceipts);
        // matchable_qty reste renseigné : « on aurait pu rapprocher 50, mais bloqué ».
        $this->assertSame('50.000', (string) $result->matchableQty);
    }

    public function test_borne_de_tolerance_prix_exactement_au_seuil_reste_dans_la_tolerance(): void
    {
        // Prix PO 100,0000 ; tolérance 1 % => 1,0000 d'écart absolu autorisé.
        $atLimit = $this->matcher->evaluate($this->input(
            orderLine: new OrderLineData(8, '10.000', '100.0000', 'X'),
            invoiceLine: new InvoiceLineData(17, '10.000', '101.0000', 'X'), // pile +1 %
            receipts: [$this->receipt(56, '2026-08-01', '10.000')],
        ));
        $this->assertSame(MatchStatus::Matched, $atLimit->status, 'écart == tolérance => accepté');

        $justOver = $this->matcher->evaluate($this->input(
            orderLine: new OrderLineData(8, '10.000', '100.0000', 'X'),
            invoiceLine: new InvoiceLineData(17, '10.000', '101.0001', 'X'), // +1,00001 %
            receipts: [$this->receipt(56, '2026-08-01', '10.000')],
        ));
        $this->assertSame(MatchStatus::NeedsReview, $justOver->status, 'écart > tolérance => revue');
    }

    public function test_sur_facturation_simple_passe_en_revue(): void
    {
        // Facturé 40 > commandé 30.
        $result = $this->matcher->evaluate($this->input(
            orderLine: new OrderLineData(9, '30.000', '12.0000', 'TN-020'),
            invoiceLine: new InvoiceLineData(18, '40.000', '12.0000', 'TN-020'),
            receipts: [$this->receipt(57, '2026-08-18', '30.000')],
        ));

        $this->assertSame(MatchStatus::NeedsReview, $result->status);
        $this->assertSame('0.000', (string) $result->authorizedQty);
        $this->assertTrue($result->hasReason(MatchReason::OverInvoiced));
        // matchable_qty = min(40, 30, 30) = 30, mais authorized_qty = 0.
        $this->assertSame('30.000', (string) $result->matchableQty);
    }

    public function test_double_facturation_cumulative_passe_en_revue(): void
    {
        // Commande 100 déjà entièrement rapprochée par une 1re facture ; 2e facture identique.
        $result = $this->matcher->evaluate($this->input(
            orderLine: new OrderLineData(10, '100.000', '8.0000', 'CIM-325'),
            invoiceLine: new InvoiceLineData(19, '100.000', '8.0000', 'CIM-325'),
            qtyAlreadyMatched: '100',
            receipts: [$this->receipt(58, '2026-08-18', '100.000', consumed: '100.000')],
        ));

        $this->assertSame(MatchStatus::NeedsReview, $result->status);
        $this->assertSame('0.000', (string) $result->authorizedQty);
        $this->assertTrue($result->hasReason(MatchReason::OverInvoiced));
    }

    public function test_fournisseur_incoherent_passe_en_revue(): void
    {
        $result = $this->matcher->evaluate($this->input(
            orderLine: new OrderLineData(11, '20.000', '15.0000', 'ENDUIT-MG'),
            invoiceLine: new InvoiceLineData(20, '20.000', '15.0000', 'ENDUIT-MG'),
            claimedSupplierId: 999, // ≠ PO_SUPPLIER
            receipts: [$this->receipt(59, '2026-08-18', '20.000')],
        ));

        $this->assertSame(MatchStatus::NeedsReview, $result->status);
        $this->assertSame('0.000', (string) $result->authorizedQty);
        $this->assertTrue($result->hasReason(MatchReason::SupplierMismatch));
        $this->assertSame(self::PO_SUPPLIER, $result->inputsSnapshot['inputs']['po_supplier_id']);
        $this->assertSame(999, $result->inputsSnapshot['inputs']['claimed_supplier_id']);
    }

    public function test_article_hors_po_passe_en_revue_sans_ecart_de_prix(): void
    {
        $result = $this->matcher->evaluate($this->input(
            orderLine: null,
            invoiceLine: new InvoiceLineData(21, '10.000', '5.0000', 'HORS-PO-ACIER'),
            receipts: [],
        ));

        $this->assertSame(MatchStatus::NeedsReview, $result->status);
        $this->assertSame('0.000', (string) $result->authorizedQty);
        $this->assertTrue($result->hasReason(MatchReason::ArticleNotInPurchaseOrder));
        $this->assertNull($result->priceDeltaPct);
        $this->assertNull($result->inputsSnapshot['inputs']['qty_ordered']);
        $this->assertNull($result->inputsSnapshot['inputs']['po_unit_price']);
    }

    public function test_plusieurs_anomalies_sont_toutes_reportees(): void
    {
        $result = $this->matcher->evaluate($this->input(
            orderLine: new OrderLineData(12, '50.000', '20.0000', 'PARP-20'),
            invoiceLine: new InvoiceLineData(22, '50.000', '25.0000', 'PARP-20'), // +25 %
            claimedSupplierId: 999,
            receipts: [$this->receipt(60, '2026-08-19', '50.000')],
        ));

        $this->assertSame(MatchStatus::NeedsReview, $result->status);
        $this->assertContains(MatchReason::SupplierMismatch->value, $result->reasonCodes());
        $this->assertContains(MatchReason::PriceOutOfTolerance->value, $result->reasonCodes());
    }

    /* ------------------------------------------------------------------ */
    /*  Tolérance quantité configurable */
    /* ------------------------------------------------------------------ */

    public function test_une_legere_sur_facturation_dans_la_tolerance_quantite_n_est_pas_une_anomalie(): void
    {
        // Commandé 30, facturé 30,4, tolérance abs 0,5 : pas d'anomalie.
        $result = $this->matcher->evaluate($this->input(
            orderLine: new OrderLineData(13, '30.000', '10.0000', 'ART'),
            invoiceLine: new InvoiceLineData(23, '30.400', '10.0000', 'ART'),
            receipts: [$this->receipt(61, '2026-08-10', '31.000')],
            tolerances: new Tolerances('0.01', '0.5'),
        ));

        $this->assertNotSame(MatchStatus::NeedsReview, $result->status);
        $this->assertFalse($result->hasReason(MatchReason::OverInvoiced));
        // Rapprochement plafonné à la quantité commandée (30), au prix PO.
        $this->assertSame('30.000', (string) $result->authorizedQty);
        $this->assertSame('300.00', (string) $result->authorizedAmount);
    }

    /* ------------------------------------------------------------------ */
    /*  Ré-évaluation (F9) */
    /* ------------------------------------------------------------------ */

    public function test_un_nouvel_arrivage_debloque_la_portion_restante(): void
    {
        $orderLine = new OrderLineData(14, '100.000', '10.0000', 'HA12');
        $invoiceLine = new InvoiceLineData(24, '100.000', '10.0000', 'HA12');

        // 1re évaluation : seuls 60 sont livrés -> partiellement rapproché.
        $step1 = $this->matcher->evaluate($this->input(
            orderLine: $orderLine,
            invoiceLine: $invoiceLine,
            receipts: [$this->receipt(62, '2026-08-21', '60.000')],
        ));
        $this->assertSame(MatchStatus::PartiallyMatched, $step1->status);
        $this->assertSame('60.000', (string) $step1->authorizedQty);

        // Un DN de 40 arrive. On REJOUE la même ligne : sa décision précédente est
        // superseded, son allocation est libérée -> le service repasse qtyAlreadyMatched
        // = 0 et le DN #62 n'est plus « déjà consommé » par cette ligne (F9).
        $step2 = $this->matcher->evaluate($this->input(
            orderLine: $orderLine,
            invoiceLine: $invoiceLine,
            qtyAlreadyMatched: '0',
            receipts: [
                $this->receipt(62, '2026-08-21', '60.000'),
                $this->receipt(63, '2026-09-01', '40.000'),
            ],
        ));

        $this->assertSame(MatchStatus::Matched, $step2->status);
        $this->assertSame('100.000', (string) $step2->authorizedQty);
        $this->assertSame('1000.00', (string) $step2->authorizedAmount);
        $this->assertConsumes($step2->consumedReceipts, [62 => '60.000', 63 => '40.000']);
    }

    /* ------------------------------------------------------------------ */
    /*  FIFO (M10) */
    /* ------------------------------------------------------------------ */

    public function test_fifo_consomme_le_bon_de_livraison_le_plus_ancien_d_abord(): void
    {
        // Les receipts sont fournis dans le désordre : le moteur trie par received_at.
        $result = $this->matcher->evaluate($this->input(
            orderLine: new OrderLineData(15, '100.000', '5.0000', 'GRAV-515'),
            invoiceLine: new InvoiceLineData(25, '60.000', '5.0000', 'GRAV-515'),
            receipts: [
                $this->receipt(70, '2026-08-24', '50.000'), // plus récent
                $this->receipt(71, '2026-08-10', '30.000'), // plus ancien -> consommé en 1er
            ],
        ));

        $this->assertSame(MatchStatus::Matched, $result->status);
        // 30 pris sur le plus ancien (71), puis 30 sur le suivant (70), dans cet ordre.
        $this->assertConsumes($result->consumedReceipts, [71 => '30.000', 70 => '30.000']);
        $this->assertSame('71', (string) $result->consumedReceipts[0]->deliveryNoteLineId);
    }

    public function test_fifo_a_deux_etapes_bascule_sur_le_dn_suivant(): void
    {
        $orderLine = new OrderLineData(16, '100.000', '5.0000', 'GRAV-515');
        $receiptsBase = [
            ['id' => 72, 'date' => '2026-08-10', 'qty' => '40.000'],
            ['id' => 73, 'date' => '2026-08-24', 'qty' => '35.000'],
        ];

        // Étape 1 : facture de 40 -> consomme entièrement le DN du 10/08.
        $step1 = $this->matcher->evaluate($this->input(
            orderLine: $orderLine,
            invoiceLine: new InvoiceLineData(26, '40.000', '5.0000', 'GRAV-515'),
            receipts: array_map(fn (array $r) => $this->receipt($r['id'], $r['date'], $r['qty']), $receiptsBase),
        ));
        $this->assertConsumes($step1->consumedReceipts, [72 => '40.000']);

        // Étape 2 : 40 déjà rapprochés et DN #72 épuisé -> la facture de 35 bascule sur #73.
        $step2 = $this->matcher->evaluate($this->input(
            orderLine: $orderLine,
            invoiceLine: new InvoiceLineData(27, '35.000', '5.0000', 'GRAV-515'),
            qtyAlreadyMatched: '40',
            receipts: [
                $this->receipt(72, '2026-08-10', '40.000', consumed: '40.000'),
                $this->receipt(73, '2026-08-24', '35.000'),
            ],
        ));
        $this->assertSame(MatchStatus::Matched, $step2->status);
        $this->assertConsumes($step2->consumedReceipts, [73 => '35.000']);
    }

    public function test_fifo_repartit_a_cheval_sur_plusieurs_dn(): void
    {
        $result = $this->matcher->evaluate($this->input(
            orderLine: new OrderLineData(17, '100.000', '5.0000', 'GRAV-515'),
            invoiceLine: new InvoiceLineData(28, '60.000', '5.0000', 'GRAV-515'),
            receipts: [
                $this->receipt(80, '2026-08-05', '30.000'),
                $this->receipt(81, '2026-08-12', '50.000'),
            ],
        ));

        $this->assertConsumes($result->consumedReceipts, [80 => '30.000', 81 => '30.000']);
        $this->assertSumOfConsumptionsEquals('60.000', $result->consumedReceipts);
    }

    public function test_fifo_respecte_la_quantite_deja_consommee_d_une_ligne_de_dn(): void
    {
        // Le DN #90 a reçu 50 mais 20 sont déjà imputés ailleurs -> 30 disponibles.
        $result = $this->matcher->evaluate($this->input(
            orderLine: new OrderLineData(18, '100.000', '5.0000', 'GRAV-515'),
            invoiceLine: new InvoiceLineData(29, '45.000', '5.0000', 'GRAV-515'),
            qtyAlreadyMatched: '20',
            receipts: [
                $this->receipt(90, '2026-08-05', '50.000', consumed: '20.000'),
                $this->receipt(91, '2026-08-12', '40.000'),
            ],
        ));

        $this->assertSame(MatchStatus::Matched, $result->status);
        $this->assertConsumes($result->consumedReceipts, [90 => '30.000', 91 => '15.000']);
        $this->assertSumOfConsumptionsEquals('45.000', $result->consumedReceipts);
    }

    /* ------------------------------------------------------------------ */
    /*  Arithmétique décimale (règle 2) */
    /* ------------------------------------------------------------------ */

    public function test_montant_calcule_sans_derive_flottante(): void
    {
        // 33,333 × 3,3333 = 111,1088889 -> 111,11 (HALF_UP, scale 2).
        $result = $this->matcher->evaluate($this->input(
            orderLine: new OrderLineData(19, '33.333', '3.3333', 'ART'),
            invoiceLine: new InvoiceLineData(30, '33.333', '3.3333', 'ART'),
            receipts: [$this->receipt(95, '2026-08-01', '33.333')],
        ));

        $this->assertSame(MatchStatus::Matched, $result->status);
        $this->assertSame('33.333', (string) $result->authorizedQty);
        $this->assertSame('111.11', (string) $result->authorizedAmount);
    }

    public function test_prix_po_nul_accepte_si_facture_nulle_sinon_revue(): void
    {
        $free = $this->matcher->evaluate($this->input(
            orderLine: new OrderLineData(20, '10.000', '0.0000', 'ECHANTILLON'),
            invoiceLine: new InvoiceLineData(31, '10.000', '0.0000', 'ECHANTILLON'),
            receipts: [$this->receipt(96, '2026-08-01', '10.000')],
        ));
        $this->assertSame(MatchStatus::Matched, $free->status);
        $this->assertSame('0.00', (string) $free->authorizedAmount);
        $this->assertSame('0.0000', (string) $free->priceDeltaPct);

        $charged = $this->matcher->evaluate($this->input(
            orderLine: new OrderLineData(20, '10.000', '0.0000', 'ECHANTILLON'),
            invoiceLine: new InvoiceLineData(31, '10.000', '5.0000', 'ECHANTILLON'),
            receipts: [$this->receipt(96, '2026-08-01', '10.000')],
        ));
        $this->assertSame(MatchStatus::NeedsReview, $charged->status);
        $this->assertTrue($charged->hasReason(MatchReason::PriceOutOfTolerance));
        $this->assertNull($charged->priceDeltaPct);
    }

    /* ------------------------------------------------------------------ */
    /*  Traçabilité (règle 10) */
    /* ------------------------------------------------------------------ */

    public function test_le_snapshot_fige_tolerances_disponibilites_et_dn_consommes(): void
    {
        $result = $this->matcher->evaluate($this->input(
            orderLine: new OrderLineData(21, '100.000', '10.0000', 'HA12'),
            invoiceLine: new InvoiceLineData(32, '80.000', '10.0500', 'HA12'), // +0,5 %, dans la tolérance
            qtyAlreadyMatched: '10',
            receipts: [$this->receipt(97, '2026-08-21', '80.000')],
        ));

        $snap = $result->inputsSnapshot;

        $this->assertSame(['price_tolerance_pct' => '0.01', 'qty_tolerance_abs' => '0.0'], $snap['inputs']['tolerances']);
        $this->assertSame('10.0000', $snap['inputs']['po_unit_price']);
        $this->assertSame('10.0500', $snap['inputs']['invoice_unit_price']);
        $this->assertSame('0.5000', $snap['inputs']['price_delta_pct']);
        $this->assertSame('90.000', $snap['inputs']['qty_available_on_order']); // 100 − 10
        $this->assertSame('80.000', $snap['inputs']['qty_available_received']);
        $this->assertSame('80.000', $snap['outcome']['authorized_qty']);
        $this->assertSame([['delivery_note_line_id' => 97, 'qty' => '80.000']], $snap['outcome']['consumed_receipts']);
    }

    public function test_deux_evaluations_du_meme_etat_donnent_le_meme_resultat(): void
    {
        $build = fn (): MatchInput => $this->input(
            orderLine: new OrderLineData(22, '100.000', '10.0000', 'HA12'),
            invoiceLine: new InvoiceLineData(33, '70.000', '10.0000', 'HA12'),
            receipts: [
                $this->receipt(98, '2026-08-10', '40.000'),
                $this->receipt(99, '2026-08-20', '40.000'),
            ],
        );

        $a = $this->matcher->evaluate($build());
        $b = $this->matcher->evaluate($build());

        $this->assertSame($a->status, $b->status);
        $this->assertSame((string) $a->authorizedAmount, (string) $b->authorizedAmount);
        $this->assertEquals($a->inputsSnapshot, $b->inputsSnapshot);
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    private function input(
        ?OrderLineData $orderLine = null,
        ?InvoiceLineData $invoiceLine = null,
        int $poSupplierId = self::PO_SUPPLIER,
        ?int $claimedSupplierId = null,
        string $qtyAlreadyMatched = '0',
        array $receipts = [],
        ?Tolerances $tolerances = null,
    ): MatchInput {
        return new MatchInput(
            orderLine: $orderLine,
            poSupplierId: $poSupplierId,
            invoiceLine: $invoiceLine ?? new InvoiceLineData(999, '1.000', '1.0000', 'DEFAULT'),
            claimedSupplierId: $claimedSupplierId ?? $poSupplierId,
            qtyAlreadyMatched: $qtyAlreadyMatched,
            receipts: $receipts,
            tolerances: $tolerances ?? Tolerances::default(),
        );
    }

    private function receipt(int $id, string $date, string $qty, string $consumed = '0'): ReceiptData
    {
        return new ReceiptData($id, new DateTimeImmutable($date), $qty, $consumed);
    }

    /**
     * @param  list<ConsumedReceipt>  $actual
     * @param  array<int, string>  $expected  deliveryNoteLineId => qty, dans l'ordre attendu
     */
    private function assertConsumes(array $actual, array $expected): void
    {
        $mapped = array_map(
            static fn (ConsumedReceipt $c): array => [$c->deliveryNoteLineId, (string) $c->qty],
            $actual,
        );
        $want = array_map(static fn ($id, $qty) => [$id, $qty], array_keys($expected), array_values($expected));

        $this->assertSame($want, $mapped);
    }

    /** @param list<ConsumedReceipt> $consumed */
    private function assertSumOfConsumptionsEquals(string $expected, array $consumed): void
    {
        $sum = BigDecimal::zero();
        foreach ($consumed as $c) {
            $sum = $sum->plus($c->qty);
        }

        $this->assertTrue(
            $sum->isEqualTo($expected),
            "Σ qty_consumed = {$sum}, attendu {$expected}",
        );
    }
}
