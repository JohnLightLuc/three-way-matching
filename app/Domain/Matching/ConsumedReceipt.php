<?php

declare(strict_types=1);

namespace App\Domain\Matching;

use Brick\Math\BigDecimal;

/**
 * Une imputation FIFO calculée par le moteur : « cette décision consomme $qty
 * unités de la ligne de DN $deliveryNoteLineId ». Le service la matérialise en
 * ligne match_decision_consumptions.
 *
 * Invariant garanti par le moteur : Σ des $qty d'une décision = son authorized_qty.
 */
final readonly class ConsumedReceipt
{
    public BigDecimal $qty;

    public function __construct(
        public int $deliveryNoteLineId,
        BigDecimal|string|int|float $qty,
    ) {
        $this->qty = Decimal::of($qty);
    }

    /** @return array{delivery_note_line_id: int, qty: string} */
    public function toArray(): array
    {
        return [
            'delivery_note_line_id' => $this->deliveryNoteLineId,
            'qty' => (string) $this->qty,
        ];
    }
}
