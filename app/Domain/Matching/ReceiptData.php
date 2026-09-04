<?php

declare(strict_types=1);

namespace App\Domain\Matching;

use Brick\Math\BigDecimal;
use DateTimeImmutable;

/**
 * Photo d'une ligne de bon de livraison rattachée à la ligne de PO en cours
 * d'évaluation.
 *
 * - qtyReceived         : quantité reçue sur cette ligne de DN.
 * - qtyAlreadyConsumed  : Σ des qty_consumed imputées par les décisions
 *   ACTUELLEMENT autorisées (M10 : une décision superseded a libéré sa part).
 * - receivedAt          : porte l'ordre FIFO (M10).
 */
final readonly class ReceiptData
{
    public BigDecimal $qtyReceived;

    public BigDecimal $qtyAlreadyConsumed;

    public function __construct(
        public int $deliveryNoteLineId,
        public DateTimeImmutable $receivedAt,
        BigDecimal|string|int|float $qtyReceived,
        BigDecimal|string|int|float $qtyAlreadyConsumed = '0',
    ) {
        $this->qtyReceived = Decimal::of($qtyReceived);
        $this->qtyAlreadyConsumed = Decimal::of($qtyAlreadyConsumed);
    }

    /** Quantité encore imputable sur cette ligne de DN (jamais négative). */
    public function availableQty(): BigDecimal
    {
        $available = $this->qtyReceived->minus($this->qtyAlreadyConsumed);

        return $available->isNegative() ? BigDecimal::zero() : $available;
    }
}
