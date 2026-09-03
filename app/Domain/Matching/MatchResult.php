<?php

declare(strict_types=1);

namespace App\Domain\Matching;

use Brick\Math\BigDecimal;

/**
 * Sortie du moteur pour une ligne de facture. Objet immuable, entièrement
 * dérivé de MatchInput de façon déterministe (règle : idempotence).
 *
 * Le service (3c) le persiste tel quel :
 *  - status / matchable_qty / authorized_qty / authorized_amount / price_delta_pct
 *    / reasons / inputs_snapshot  -> match_decisions
 *  - consumedReceipts                                            -> match_decision_consumptions
 *  - authorized_qty / authorized_amount (si > 0)                 -> payment_authorizations
 */
final readonly class MatchResult
{
    /**
     * @param  list<Reason>  $reasons
     * @param  list<ConsumedReceipt>  $consumedReceipts
     * @param  array<string, mixed>  $inputsSnapshot
     */
    public function __construct(
        public MatchStatus $status,
        public BigDecimal $matchableQty,
        public BigDecimal $authorizedQty,
        public BigDecimal $authorizedAmount,
        public ?BigDecimal $priceDeltaPct,
        public array $reasons,
        public array $consumedReceipts,
        public array $inputsSnapshot,
    ) {}

    /** Une portion de paiement est-elle effectivement autorisée ? */
    public function authorizesPayment(): bool
    {
        return $this->status->authorizesPayment() && $this->authorizedQty->isPositive();
    }

    public function hasAnomaly(): bool
    {
        foreach ($this->reasons as $reason) {
            if ($reason->isAnomaly()) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public function reasonCodes(): array
    {
        return array_map(static fn (Reason $r): string => $r->code->value, $this->reasons);
    }

    public function hasReason(MatchReason $code): bool
    {
        return in_array($code->value, $this->reasonCodes(), true);
    }
}
