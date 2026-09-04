<?php

declare(strict_types=1);

namespace App\Domain\Matching;

/**
 * Motif d'une décision : un code stable (MatchReason) + un contexte chiffré,
 * destiné à la fois à match_decisions.reasons et à inputs_snapshot (règle 10).
 * Ex. : PriceOutOfTolerance avec { po_unit_price, invoice_unit_price, delta_pct, tolerance_pct }.
 */
final readonly class Reason
{
    /** @param array<string, scalar> $context */
    public function __construct(
        public MatchReason $code,
        public array $context = [],
    ) {}

    public function isAnomaly(): bool
    {
        return $this->code->isAnomaly();
    }

    /** @return array{code: string, context: array<string, scalar>} */
    public function toArray(): array
    {
        return [
            'code' => $this->code->value,
            'context' => $this->context,
        ];
    }
}
