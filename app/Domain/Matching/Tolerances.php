<?php

declare(strict_types=1);

namespace App\Domain\Matching;

use Brick\Math\BigDecimal;

/**
 * Seuils appliqués par le moteur. Construits par le service à partir de
 * config('matching') — jamais lus depuis la config ici (règle 8 : cœur isolé).
 * Recopiés dans inputs_snapshot à chaque décision (règle 10 / M6).
 */
final readonly class Tolerances
{
    public BigDecimal $priceTolerancePct;

    public BigDecimal $qtyToleranceAbs;

    public function __construct(
        BigDecimal|string|int|float $priceTolerancePct,
        BigDecimal|string|int|float $qtyToleranceAbs,
    ) {
        $this->priceTolerancePct = Decimal::of($priceTolerancePct);
        $this->qtyToleranceAbs = Decimal::of($qtyToleranceAbs);
    }

    /** Tolérances par défaut du projet (config/matching.php). */
    public static function default(): self
    {
        return new self('0.01', '0.0');
    }

    /** @return array{price_tolerance_pct: string, qty_tolerance_abs: string} */
    public function toArray(): array
    {
        return [
            'price_tolerance_pct' => (string) $this->priceTolerancePct,
            'qty_tolerance_abs' => (string) $this->qtyToleranceAbs,
        ];
    }
}
