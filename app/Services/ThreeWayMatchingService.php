<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Matching\InvoiceLineData;
use App\Domain\Matching\MatchInput;
use App\Domain\Matching\MatchReason;
use App\Domain\Matching\MatchResult;
use App\Domain\Matching\MatchStatus;
use App\Domain\Matching\OrderLineData;
use App\Domain\Matching\Reason;
use App\Domain\Matching\ReceiptData;
use App\Domain\Matching\ThreeWayMatcher;
use App\Domain\Matching\Tolerances;
use App\Models\DeliveryNoteLine;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\MatchDecision;
use App\Models\MatchDecisionConsumption;
use App\Models\PaymentAuthorization;
use App\Models\PurchaseOrderLine;
use App\Models\User;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Orchestration domaine <-> persistance (CLAUDE.md). Charge l'état, appelle
 * ThreeWayMatcher, écrit décisions / consommations / autorisations dans une
 * TRANSACTION avec verrou sur la/les ligne(s) de PO (règle 9).
 *
 * Invariants tenus ici :
 *  - match_decisions append-only : une nouvelle évaluation = nouvelle ligne
 *    liée par supersedes_id (règle 3 / M5) ; l'ancienne autorisation passe revoked.
 *  - idempotence (règle 9) : si la nouvelle évaluation est équivalente à la
 *    décision courante, on n'écrit rien.
 *  - ré-évaluation (F9) : le pool exclut la décision courante de la ligne évaluée
 *    (une décision superseded libère son allocation — règle 7 / M10).
 *  - une décision COURANTE prise par un humain (actor_type=user) est « collante » :
 *    matchInvoice ne la réécrase pas ; seul un nouveau review la change (F10).
 */
final class ThreeWayMatchingService
{
    public function __construct(private readonly ThreeWayMatcher $matcher) {}

    /**
     * Rapproche toutes les lignes d'une facture. `$triggeredBy` n'entre pas dans
     * la décision (actor_type=system) — l'auteur du déclenchement est tracé par
     * le journal d'activité.
     *
     * @return Collection<int, MatchDecision> décisions courantes après rapprochement
     */
    public function matchInvoice(Invoice $invoice, User $triggeredBy): Collection
    {
        return DB::transaction(function () use ($invoice): Collection {
            $invoice->loadMissing('purchaseOrder', 'lines.purchaseOrderLine');
            $invoice->lines->each(fn (InvoiceLine $line) => $line->setRelation('invoice', $invoice));

            $this->lockPurchaseOrderLines(
                $invoice->lines->pluck('purchase_order_line_id')->filter()->all()
            );

            $decisions = $invoice->lines
                ->map(fn (InvoiceLine $line): MatchDecision => $this->evaluateLine($line))
                ->values();

            $this->refreshInvoiceStatus($invoice);

            return $decisions;
        });
    }

    /**
     * Révision humaine d'une décision needs_review (F10) : crée une NOUVELLE
     * décision actor_type=user liée par supersedes_id, sans écraser l'historique.
     */
    public function review(MatchDecision $decision, string $action, User $actor, ?string $authorizedQtyOverride = null): MatchDecision
    {
        if (! in_array($action, ['approve', 'reject'], true)) {
            throw new InvalidArgumentException("Action de révision inconnue : {$action}.");
        }

        return DB::transaction(function () use ($decision, $action, $actor, $authorizedQtyOverride): MatchDecision {
            abort_if($decision->supersededBy()->exists(), 409, 'Cette décision a déjà été remplacée.');
            abort_if(
                $decision->status !== MatchStatus::NeedsReview->value,
                422,
                'Seule une décision en needs_review peut être révisée.'
            );

            /** @var InvoiceLine $line */
            $line = $decision->invoiceLine()->with(['invoice.purchaseOrder', 'purchaseOrderLine'])->firstOrFail();

            $this->lockPurchaseOrderLines(array_filter([$line->purchase_order_line_id]));

            $new = $action === 'approve'
                ? $this->approve($decision, $line, $actor, $authorizedQtyOverride)
                : $this->reject($decision, $line, $actor);

            $this->refreshInvoiceStatus($line->invoice->load('lines'));

            return $new;
        });
    }

    /* ------------------------------------------------------------------ */
    /*  Flux système (matchInvoice) */
    /* ------------------------------------------------------------------ */

    private function evaluateLine(InvoiceLine $line): MatchDecision
    {
        $current = $this->currentDecision($line);

        // Une décision humaine est souveraine : on ne la ré-évalue pas (F10).
        if ($current !== null && $current->actor_type === 'user') {
            return $current;
        }

        $result = $this->matcher->evaluate($this->buildInput($line));

        if ($current !== null && $this->equivalent($current, $result)) {
            return $current; // idempotence (règle 9)
        }

        return $this->persist($line, $result, $current, 'system', null);
    }

    /* ------------------------------------------------------------------ */
    /*  Révision (review) */
    /* ------------------------------------------------------------------ */

    private function approve(MatchDecision $decision, InvoiceLine $line, User $actor, ?string $override): MatchDecision
    {
        abort_if(
            $line->purchase_order_line_id === null,
            422,
            'Un article hors PO ne peut pas être approuvé (aucun prix de commande) — utilisez reject.'
        );

        try {
            $result = $this->matcher->authorizeOverride(
                $this->buildInput($line),
                $override === null ? null : BigDecimal::of($override),
            );
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        return $this->persist(
            $line,
            $result,
            $decision,
            'user',
            $actor,
            extraReasons: [[
                'code' => MatchReason::ReviewApproved->value,
                'context' => [
                    'by' => $actor->id,
                    'original_reasons' => $this->reasonCodes($decision),
                ],
            ]],
            snapshotExtra: ['review' => [
                'action' => 'approve',
                'by' => $actor->id,
                'at' => now()->toIso8601String(),
                'overridden_qty' => $override,
            ]],
        );
    }

    private function reject(MatchDecision $decision, InvoiceLine $line, User $actor): MatchDecision
    {
        $new = MatchDecision::create([
            'invoice_line_id' => $line->id,
            'purchase_order_line_id' => $line->purchase_order_line_id,
            'status' => MatchStatus::NeedsReview->value,
            'matchable_qty' => (string) $decision->matchable_qty,
            'authorized_qty' => '0.000',
            'authorized_amount' => '0.00',
            'price_delta_pct' => $decision->price_delta_pct === null ? null : (string) $decision->price_delta_pct,
            'reasons' => array_merge($decision->reasons ?? [], [[
                'code' => MatchReason::ReviewRejected->value,
                'context' => ['by' => $actor->id],
            ]]),
            'actor_type' => 'user',
            'actor_user_id' => $actor->id,
            'decided_at' => now(),
            'inputs_snapshot' => array_merge($decision->inputs_snapshot ?? [], [
                'review' => ['action' => 'reject', 'by' => $actor->id, 'at' => now()->toIso8601String()],
            ]),
            'supersedes_id' => $decision->id,
        ]);

        $this->revokeActivePaymentAuthorization($line);

        return $new;
    }

    /* ------------------------------------------------------------------ */
    /*  Construction de l'entrée moteur */
    /* ------------------------------------------------------------------ */

    private function buildInput(InvoiceLine $line): MatchInput
    {
        $invoice = $line->invoice;

        $invoiceLineData = new InvoiceLineData(
            id: $line->id,
            qtyInvoiced: (string) $line->qty_invoiced,
            unitPrice: (string) $line->unit_price,
            articleCode: $line->article_code,
        );

        $poLine = $line->purchaseOrderLine;

        if ($poLine === null) {
            return new MatchInput(
                orderLine: null,
                poSupplierId: $invoice->purchaseOrder->supplier_id,
                invoiceLine: $invoiceLineData,
                claimedSupplierId: $invoice->supplier_id,
                qtyAlreadyMatched: '0',
                receipts: [],
                tolerances: $this->tolerances(),
            );
        }

        $pool = $this->poolExcluding($poLine->id, $line->id);

        $receipts = $poLine->deliveryNoteLines()
            ->with('deliveryNote')
            ->get()
            ->map(fn (DeliveryNoteLine $dnl): ReceiptData => new ReceiptData(
                deliveryNoteLineId: $dnl->id,
                receivedAt: new DateTimeImmutable($dnl->deliveryNote->received_at->toDateString()),
                qtyReceived: (string) $dnl->qty_received,
                qtyAlreadyConsumed: (string) ($pool['consumedByDnLine'][$dnl->id] ?? '0'),
            ))
            ->all();

        return new MatchInput(
            orderLine: new OrderLineData(
                id: $poLine->id,
                qtyOrdered: (string) $poLine->qty_ordered,
                unitPrice: (string) $poLine->unit_price,
                articleCode: $poLine->article_code,
            ),
            poSupplierId: $invoice->purchaseOrder->supplier_id,
            invoiceLine: $invoiceLineData,
            claimedSupplierId: $invoice->supplier_id,
            qtyAlreadyMatched: (string) $pool['matched'],
            receipts: $receipts,
            tolerances: $this->tolerances(),
        );
    }

    /**
     * Pool d'allocation de la ligne de PO, décisions COURANTES des AUTRES lignes
     * de facture uniquement (exclut la ligne évaluée -> ré-évaluation F9).
     *
     * @return array{matched: BigDecimal, consumedByDnLine: array<int, BigDecimal>}
     */
    private function poolExcluding(int $poLineId, int $excludeInvoiceLineId): array
    {
        $currents = MatchDecision::query()
            ->current()
            ->where('invoice_line_id', '!=', $excludeInvoiceLineId)
            ->whereHas('invoiceLine', fn ($q) => $q->where('purchase_order_line_id', $poLineId))
            ->with('consumptions')
            ->get();

        $matched = BigDecimal::zero();
        $consumedByDnLine = [];

        foreach ($currents as $decision) {
            $matched = $matched->plus(BigDecimal::of((string) $decision->authorized_qty));

            foreach ($decision->consumptions as $consumption) {
                $id = $consumption->delivery_note_line_id;
                $consumedByDnLine[$id] = ($consumedByDnLine[$id] ?? BigDecimal::zero())
                    ->plus(BigDecimal::of((string) $consumption->qty_consumed));
            }
        }

        return ['matched' => $matched, 'consumedByDnLine' => $consumedByDnLine];
    }

    private function tolerances(): Tolerances
    {
        return new Tolerances(
            (string) config('matching.price_tolerance_pct'),
            (string) config('matching.qty_tolerance_abs'),
        );
    }

    /* ------------------------------------------------------------------ */
    /*  Persistance d'un MatchResult */
    /* ------------------------------------------------------------------ */

    /**
     * @param  list<array{code: string, context: array<string, mixed>}>  $extraReasons
     * @param  array<string, mixed>  $snapshotExtra
     */
    private function persist(
        InvoiceLine $line,
        MatchResult $result,
        ?MatchDecision $supersedes,
        string $actorType,
        ?User $actor,
        array $extraReasons = [],
        array $snapshotExtra = [],
    ): MatchDecision {
        $decision = MatchDecision::create([
            'invoice_line_id' => $line->id,
            'purchase_order_line_id' => $line->purchase_order_line_id,
            'status' => $result->status->value,
            'matchable_qty' => (string) $result->matchableQty,
            'authorized_qty' => (string) $result->authorizedQty,
            'authorized_amount' => (string) $result->authorizedAmount,
            'price_delta_pct' => $result->priceDeltaPct === null ? null : (string) $result->priceDeltaPct,
            'reasons' => array_merge(
                array_map(static fn (Reason $r): array => $r->toArray(), $result->reasons),
                $extraReasons,
            ),
            'actor_type' => $actorType,
            'actor_user_id' => $actor?->id,
            'decided_at' => now(),
            'inputs_snapshot' => array_merge($result->inputsSnapshot, $snapshotExtra),
            'supersedes_id' => $supersedes?->id,
        ]);

        foreach ($result->consumedReceipts as $consumed) {
            MatchDecisionConsumption::create([
                'match_decision_id' => $decision->id,
                'delivery_note_line_id' => $consumed->deliveryNoteLineId,
                'qty_consumed' => (string) $consumed->qty,
            ]);
        }

        $this->revokeActivePaymentAuthorization($line);

        if ($result->authorizesPayment()) {
            PaymentAuthorization::create([
                'invoice_line_id' => $line->id,
                'match_decision_id' => $decision->id,
                'authorized_qty' => (string) $result->authorizedQty,
                'authorized_amount' => (string) $result->authorizedAmount,
                'status' => 'authorized',
            ]);
        }

        return $decision;
    }

    private function revokeActivePaymentAuthorization(InvoiceLine $line): void
    {
        PaymentAuthorization::query()
            ->where('invoice_line_id', $line->id)
            ->where('status', 'authorized')
            ->update(['status' => 'revoked', 'updated_at' => now()]);
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    private function currentDecision(InvoiceLine $line): ?MatchDecision
    {
        return MatchDecision::query()
            ->where('invoice_line_id', $line->id)
            ->current()
            ->orderByDesc('id')
            ->first();
    }

    private function equivalent(MatchDecision $current, MatchResult $result): bool
    {
        if ($current->status !== $result->status->value) {
            return false;
        }

        $eq = static fn (int|string|null $a, ?BigDecimal $b): bool => ($a === null) === ($b === null)
            && ($b === null || BigDecimal::of((string) $a)->isEqualTo($b));

        if (! $eq($current->authorized_qty, $result->authorizedQty)
            || ! $eq($current->matchable_qty, $result->matchableQty)
            || ! $eq($current->authorized_amount, $result->authorizedAmount)
            || ! $eq($current->price_delta_pct, $result->priceDeltaPct)) {
            return false;
        }

        $resultCodes = collect($result->reasons)->map(fn (Reason $r): string => $r->code->value)->sort()->values()->all();
        if ($this->reasonCodes($current) !== $resultCodes) {
            return false;
        }

        $norm = static fn (int|float|string $q): string => (string) BigDecimal::of((string) $q)->toScale(3);

        $currentConsumed = $current->consumptions
            ->mapWithKeys(fn (MatchDecisionConsumption $c): array => [$c->delivery_note_line_id => $norm($c->qty_consumed)])
            ->all();
        ksort($currentConsumed);

        $resultConsumed = [];
        foreach ($result->consumedReceipts as $consumed) {
            $resultConsumed[$consumed->deliveryNoteLineId] = $norm((string) $consumed->qty);
        }
        ksort($resultConsumed);

        return $currentConsumed === $resultConsumed;
    }

    /** @return list<string> */
    private function reasonCodes(MatchDecision $decision): array
    {
        return collect($decision->reasons ?? [])
            ->pluck('code')
            ->filter()
            ->sort()
            ->values()
            ->all();
    }

    /** @param  array<int, int|string>  $poLineIds */
    private function lockPurchaseOrderLines(array $poLineIds): void
    {
        $ids = collect($poLineIds)->unique()->sort()->values();

        if ($ids->isEmpty()) {
            return;
        }

        PurchaseOrderLine::query()
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    private function refreshInvoiceStatus(Invoice $invoice): void
    {
        $statuses = $invoice->lines
            ->map(fn (InvoiceLine $line): ?string => $this->currentDecision($line)?->status)
            ->filter()
            ->values();

        if ($statuses->isEmpty()) {
            return; // pas encore rapprochée
        }

        $paying = [MatchStatus::Matched->value, MatchStatus::PartiallyMatched->value];

        $new = match (true) {
            $statuses->contains(MatchStatus::NeedsReview->value) => 'needs_review',
            $statuses->every(fn (string $s): bool => $s === MatchStatus::Matched->value) => 'matched',
            $statuses->contains(fn (string $s): bool => in_array($s, $paying, true)) => 'partially_matched',
            default => 'submitted',
        };

        if ($invoice->status !== $new) {
            $invoice->update(['status' => $new]);
        }
    }
}
