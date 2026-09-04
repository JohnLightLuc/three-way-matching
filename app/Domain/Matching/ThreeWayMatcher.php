<?php

declare(strict_types=1);

namespace App\Domain\Matching;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;
use LogicException;

/**
 * Cœur du rapprochement à 3 voies (CONCEPTION.md §3). PHP pur, sans état, sans
 * dépendance framework (règle 8). Déterministe : même MatchInput -> même
 * MatchResult (idempotence, règle 9).
 *
 * Rapprochement PAR LIGNE DE FACTURE, contre sa ligne de PO, avec un pool
 * d'allocation partagé sur la ligne de PO :
 *
 *   dispo_commande = qté_commandée − qté_déjà_rapprochée
 *   dispo_reçue    = Σ qté_reçue    − Σ qté_déjà_consommée
 *   rapprochable   = min(qté_facturée, dispo_commande, dispo_reçue)
 *
 * Anomalie (prix hors tolérance, sur-facturation, fournisseur incohérent, article
 * hors PO) -> needs_review, on n'autorise RIEN (règles 4 & 6). Ligne saine mais
 * incomplète -> on autorise la portion livrée (partially_matched). Montant
 * TOUJOURS au prix du PO (règle 4). DN imputés en FIFO (M10).
 */
final class ThreeWayMatcher
{
    private const QTY_SCALE = 3;

    private const MONEY_SCALE = 2;

    private const PCT_SCALE = 4;

    public function evaluate(MatchInput $input): MatchResult
    {
        $priceDeltaPct = $this->priceDeltaPct($input);
        $reasons = $this->detectAnomalies($input, $priceDeltaPct);

        $received = $this->sum($input->receipts, static fn (ReceiptData $r) => $r->qtyReceived);
        $consumedElsewhere = $this->sum($input->receipts, static fn (ReceiptData $r) => $r->qtyAlreadyConsumed);
        $availReceived = $this->floorZero($received->minus($consumedElsewhere));

        // Article hors PO : ni quantité commandée ni prix comparables -> revue directe.
        if ($input->orderLine === null) {
            return $this->build(
                MatchStatus::NeedsReview,
                matchableQty: BigDecimal::zero(),
                authorizedQty: BigDecimal::zero(),
                priceDeltaPct: null,
                reasons: $reasons,
                consumed: [],
                input: $input,
                availOrder: null,
                availReceived: $availReceived,
                receivedTotal: $received,
            );
        }

        $availOrder = $this->floorZero($input->orderLine->qtyOrdered->minus($input->qtyAlreadyMatched));
        $qtyInvoiced = $input->invoiceLine->qtyInvoiced;
        $matchableQty = BigDecimal::min($qtyInvoiced, $availOrder, $availReceived);

        if ($reasons !== []) {
            return $this->build(
                MatchStatus::NeedsReview,
                matchableQty: $matchableQty,
                authorizedQty: BigDecimal::zero(),
                priceDeltaPct: $priceDeltaPct,
                reasons: $reasons,
                consumed: [],
                input: $input,
                availOrder: $availOrder,
                availReceived: $availReceived,
                receivedTotal: $received,
            );
        }

        // Ligne saine : détermination du statut.
        if ($matchableQty->isZero()) {
            $status = MatchStatus::PendingReceipt;
            $reasons[] = new Reason(MatchReason::NothingReceived, [
                'qty_received_total' => (string) $received,
                'qty_available_received' => (string) $availReceived,
                'qty_available_on_order' => (string) $availOrder,
            ]);
        } elseif ($matchableQty->isEqualTo($qtyInvoiced)) {
            $status = MatchStatus::Matched;
        } else {
            $status = MatchStatus::PartiallyMatched;
            $reasons[] = new Reason(MatchReason::PartialReceipt, [
                'qty_invoiced' => (string) $qtyInvoiced,
                'qty_authorized' => (string) $this->qty($matchableQty),
                'qty_available_received' => (string) $availReceived,
            ]);
        }

        $authorizedQty = $status->authorizesPayment() ? $this->qty($matchableQty) : BigDecimal::zero();
        $consumed = $authorizedQty->isPositive()
            ? $this->allocateFifo($input->receipts, $authorizedQty)
            : [];

        return $this->build(
            $status,
            matchableQty: $matchableQty,
            authorizedQty: $authorizedQty,
            priceDeltaPct: $priceDeltaPct,
            reasons: $reasons,
            consumed: $consumed,
            input: $input,
            availOrder: $availOrder,
            availReceived: $availReceived,
            receivedTotal: $received,
        );
    }

    /**
     * Autorisation FORCÉE par une révision humaine (F10) : ignore la détection
     * d'anomalie, mais réutilise le même calcul de disponibilités, le même
     * plafond (rapprochable) et la même imputation FIFO — rien de « métier » ne
     * fuit dans le service. Le motif review_approved et l'acteur sont ajoutés
     * par le service qui persiste.
     *
     * @param  BigDecimal|null  $qtyOverride  quantité imposée par le réviseur ; null = tout le rapprochable
     *
     * @throws InvalidArgumentException si pas de ligne de PO, ou qté ≤ 0, ou qté > rapprochable
     */
    public function authorizeOverride(MatchInput $input, ?BigDecimal $qtyOverride = null): MatchResult
    {
        if ($input->orderLine === null) {
            throw new InvalidArgumentException(
                'authorizeOverride exige une ligne de PO : un article hors PO ne peut pas être approuvé (aucun prix de commande).'
            );
        }

        $received = $this->sum($input->receipts, static fn (ReceiptData $r) => $r->qtyReceived);
        $consumedElsewhere = $this->sum($input->receipts, static fn (ReceiptData $r) => $r->qtyAlreadyConsumed);
        $availReceived = $this->floorZero($received->minus($consumedElsewhere));
        $availOrder = $this->floorZero($input->orderLine->qtyOrdered->minus($input->qtyAlreadyMatched));

        $qtyInvoiced = $input->invoiceLine->qtyInvoiced;
        $matchableQty = BigDecimal::min($qtyInvoiced, $availOrder, $availReceived);

        $authorizedQty = $qtyOverride ?? $matchableQty;

        if ($authorizedQty->isNegativeOrZero()) {
            throw new InvalidArgumentException('La quantité à autoriser doit être strictement positive (sinon : reject).');
        }

        if ($authorizedQty->isGreaterThan($matchableQty)) {
            throw new InvalidArgumentException(sprintf(
                'Quantité demandée (%s) supérieure au rapprochable (%s) : commande ou réception insuffisantes.',
                $authorizedQty,
                $matchableQty,
            ));
        }

        $authorizedQty = $this->qty($authorizedQty);

        $status = $authorizedQty->isEqualTo($this->qty($qtyInvoiced))
            ? MatchStatus::Matched
            : MatchStatus::PartiallyMatched;

        $reasons = $status === MatchStatus::PartiallyMatched
            ? [new Reason(MatchReason::PartialReceipt, [
                'qty_invoiced' => (string) $qtyInvoiced,
                'qty_authorized' => (string) $authorizedQty,
            ])]
            : [];

        return $this->build(
            $status,
            matchableQty: $matchableQty,
            authorizedQty: $authorizedQty,
            priceDeltaPct: $this->priceDeltaPct($input),
            reasons: $reasons,
            consumed: $this->allocateFifo($input->receipts, $authorizedQty),
            input: $input,
            availOrder: $availOrder,
            availReceived: $availReceived,
            receivedTotal: $received,
        );
    }

    /* --------------------------------------------------------------------- */
    /*  Anomalies (règle 5) */
    /* --------------------------------------------------------------------- */

    /** @return list<Reason> */
    private function detectAnomalies(MatchInput $input, ?BigDecimal $priceDeltaPct): array
    {
        $reasons = [];

        // Fournisseur revendiqué ≠ fournisseur du PO (F8) — vérifiable même hors PO.
        if ($input->claimedSupplierId !== $input->poSupplierId) {
            $reasons[] = new Reason(MatchReason::SupplierMismatch, [
                'po_supplier_id' => $input->poSupplierId,
                'claimed_supplier_id' => $input->claimedSupplierId,
            ]);
        }

        // Article facturé absent du PO (M2).
        if ($input->orderLine === null) {
            $reasons[] = new Reason(MatchReason::ArticleNotInPurchaseOrder, [
                'invoice_article_code' => $input->invoiceLine->articleCode,
            ]);

            return $reasons; // sans ligne de PO, on ne peut juger ni prix ni sur-facturation
        }

        // Sur-facturation : facturé > (commandé − déjà rapproché) + tolérance abs (F7).
        $availOrder = $input->orderLine->qtyOrdered->minus($input->qtyAlreadyMatched);
        $overThreshold = $availOrder->plus($input->tolerances->qtyToleranceAbs);
        if ($input->invoiceLine->qtyInvoiced->isGreaterThan($overThreshold)) {
            $reasons[] = new Reason(MatchReason::OverInvoiced, [
                'qty_invoiced' => (string) $input->invoiceLine->qtyInvoiced,
                'qty_ordered' => (string) $input->orderLine->qtyOrdered,
                'qty_already_matched' => (string) $input->qtyAlreadyMatched,
                'qty_available_on_order' => (string) $this->floorZero($availOrder),
                'qty_tolerance_abs' => (string) $input->tolerances->qtyToleranceAbs,
            ]);
        }

        // Prix hors tolérance (F6). Test EXACT : |inv − po| > po × tol_fraction.
        if ($this->priceOutOfTolerance($input)) {
            $reasons[] = new Reason(MatchReason::PriceOutOfTolerance, [
                'po_unit_price' => (string) $input->orderLine->unitPrice,
                'invoice_unit_price' => (string) $input->invoiceLine->unitPrice,
                'delta_pct' => $priceDeltaPct === null ? null : (string) $priceDeltaPct,
                'tolerance_pct' => (string) $input->tolerances->priceTolerancePct,
            ]);
        }

        return $reasons;
    }

    private function priceOutOfTolerance(MatchInput $input): bool
    {
        /** @var OrderLineData $orderLine */
        $orderLine = $input->orderLine;
        $po = $orderLine->unitPrice;
        $invoice = $input->invoiceLine->unitPrice;

        if ($po->isZero()) {
            return ! $invoice->isZero(); // 0 vs 0 : OK ; 0 vs >0 : hors tolérance
        }

        $absDiff = $invoice->minus($po)->abs();
        $limit = $po->multipliedBy($input->tolerances->priceTolerancePct)->abs();

        return $absDiff->isGreaterThan($limit);
    }

    /** Écart de prix en POURCENTAGE signé (3.0000 = +3 %), scale 4 ; null si hors PO ou prix PO nul. */
    private function priceDeltaPct(MatchInput $input): ?BigDecimal
    {
        if ($input->orderLine === null) {
            return null;
        }

        $po = $input->orderLine->unitPrice;
        $invoice = $input->invoiceLine->unitPrice;

        if ($po->isZero()) {
            return $invoice->isZero() ? BigDecimal::zero()->toScale(self::PCT_SCALE) : null;
        }

        return $invoice->minus($po)
            ->multipliedBy(100)
            ->dividedBy($po, self::PCT_SCALE, RoundingMode::HalfUp);
    }

    /* --------------------------------------------------------------------- */
    /*  Imputation FIFO (M10) */
    /* --------------------------------------------------------------------- */

    /**
     * @param  list<ReceiptData>  $receipts
     * @return list<ConsumedReceipt>
     */
    private function allocateFifo(array $receipts, BigDecimal $authorizedQty): array
    {
        usort($receipts, static fn (ReceiptData $a, ReceiptData $b): int => (
            $a->receivedAt <=> $b->receivedAt ?: $a->deliveryNoteLineId <=> $b->deliveryNoteLineId
        ));

        $remaining = $authorizedQty;
        $consumed = [];

        foreach ($receipts as $receipt) {
            if (! $remaining->isPositive()) {
                break;
            }

            $take = BigDecimal::min($receipt->availableQty(), $remaining);

            if ($take->isPositive()) {
                $consumed[] = new ConsumedReceipt($receipt->deliveryNoteLineId, $take);
                $remaining = $remaining->minus($take);
            }
        }

        if ($remaining->isPositive()) {
            throw new LogicException(
                'FIFO : allocation incomplète — authorized_qty dépasse le stock disponible (incohérence moteur).'
            );
        }

        return $consumed;
    }

    /* --------------------------------------------------------------------- */
    /*  Assemblage du résultat + snapshot (règle 10) */
    /* --------------------------------------------------------------------- */

    /**
     * @param  list<Reason>  $reasons
     * @param  list<ConsumedReceipt>  $consumed
     */
    private function build(
        MatchStatus $status,
        BigDecimal $matchableQty,
        BigDecimal $authorizedQty,
        ?BigDecimal $priceDeltaPct,
        array $reasons,
        array $consumed,
        MatchInput $input,
        ?BigDecimal $availOrder,
        BigDecimal $availReceived,
        BigDecimal $receivedTotal,
    ): MatchResult {
        $matchableQty = $this->qty($matchableQty);
        $authorizedQty = $this->qty($authorizedQty);
        $unitPrice = $input->orderLine?->unitPrice;
        $authorizedAmount = ($unitPrice ?? BigDecimal::zero())
            ->multipliedBy($authorizedQty)
            ->toScale(self::MONEY_SCALE, RoundingMode::HalfUp);
        $priceDeltaPct = $priceDeltaPct?->toScale(self::PCT_SCALE, RoundingMode::HalfUp);

        $snapshot = [
            'inputs' => [
                'order_line_id' => $input->orderLine?->id,
                'po_article_code' => $input->orderLine?->articleCode,
                'invoice_line_id' => $input->invoiceLine->id,
                'invoice_article_code' => $input->invoiceLine->articleCode,
                'po_supplier_id' => $input->poSupplierId,
                'claimed_supplier_id' => $input->claimedSupplierId,
                'qty_ordered' => $this->nullableString($input->orderLine?->qtyOrdered),
                'qty_already_matched' => (string) $input->qtyAlreadyMatched,
                'qty_received_total' => (string) $receivedTotal,
                'qty_available_on_order' => $this->nullableString($availOrder),
                'qty_available_received' => (string) $availReceived,
                'qty_invoiced' => (string) $input->invoiceLine->qtyInvoiced,
                'po_unit_price' => $this->nullableString($unitPrice),
                'invoice_unit_price' => (string) $input->invoiceLine->unitPrice,
                'price_delta_pct' => $priceDeltaPct === null ? null : (string) $priceDeltaPct,
                'tolerances' => $input->tolerances->toArray(),
            ],
            'outcome' => [
                'status' => $status->value,
                'matchable_qty' => (string) $matchableQty,
                'authorized_qty' => (string) $authorizedQty,
                'authorized_amount' => (string) $authorizedAmount,
                'reasons' => array_map(static fn (Reason $r) => $r->toArray(), $reasons),
                'consumed_receipts' => array_map(static fn (ConsumedReceipt $c) => $c->toArray(), $consumed),
            ],
        ];

        return new MatchResult(
            $status,
            $matchableQty,
            $authorizedQty,
            $authorizedAmount,
            $priceDeltaPct,
            $reasons,
            $consumed,
            $snapshot,
        );
    }

    /* --------------------------------------------------------------------- */
    /*  Helpers décimaux */
    /* --------------------------------------------------------------------- */

    /**
     * @param  list<ReceiptData>  $receipts
     * @param  callable(ReceiptData): BigDecimal  $pick
     */
    private function sum(array $receipts, callable $pick): BigDecimal
    {
        $total = BigDecimal::zero();

        foreach ($receipts as $receipt) {
            $total = $total->plus($pick($receipt));
        }

        return $total;
    }

    private function floorZero(BigDecimal $value): BigDecimal
    {
        return $value->isNegative() ? BigDecimal::zero() : $value;
    }

    private function qty(BigDecimal $value): BigDecimal
    {
        return $value->toScale(self::QTY_SCALE, RoundingMode::HalfUp);
    }

    private function nullableString(?BigDecimal $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
