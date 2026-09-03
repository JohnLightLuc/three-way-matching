<?php

declare(strict_types=1);

namespace App\Domain\Matching;

use Brick\Math\BigDecimal;

/**
 * Photo d'une ligne de PO — la SOURCE DE VÉRITÉ (règle 1). Le moteur ne lit le
 * prix « juste » et la quantité commandée que d'ici, jamais de la facture.
 */
final readonly class OrderLineData
{
    public BigDecimal $qtyOrdered;

    public BigDecimal $unitPrice;

    public function __construct(
        public int $id,
        BigDecimal|string|int|float $qtyOrdered,
        BigDecimal|string|int|float $unitPrice,
        public string $articleCode,
    ) {
        $this->qtyOrdered = Decimal::of($qtyOrdered);
        $this->unitPrice = Decimal::of($unitPrice);
    }
}
