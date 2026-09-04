<?php

declare(strict_types=1);

namespace App\Domain\Matching;

use Brick\Math\BigDecimal;

/**
 * Photo d'une ligne de facture — données BRUTES telles que facturées (M2).
 * Le moteur les compare à la ligne de PO ; il n'autorise jamais AU prix facturé.
 */
final readonly class InvoiceLineData
{
    public BigDecimal $qtyInvoiced;

    public BigDecimal $unitPrice;

    public function __construct(
        public int $id,
        BigDecimal|string|int|float $qtyInvoiced,
        BigDecimal|string|int|float $unitPrice,
        public string $articleCode,
    ) {
        $this->qtyInvoiced = Decimal::of($qtyInvoiced);
        $this->unitPrice = Decimal::of($unitPrice);
    }
}
