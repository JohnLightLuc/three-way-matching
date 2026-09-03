<?php

declare(strict_types=1);

namespace App\Domain\Matching;

use Brick\Math\BigDecimal;

/**
 * Normalisation décimale du cœur métier (règle 2 : jamais de float pour money/qté).
 *
 * brick/math 0.18 : BigNumber::of() n'accepte PAS les float (ils sont silencieusement
 * tronqués en int). Ce helper est le SEUL point d'entrée toléré pour un float — il le
 * transforme en chaîne exacte avant conversion. Partout ailleurs on manipule des
 * BigDecimal, des chaînes ou des int.
 */
final class Decimal
{
    /** Décimales conservées lors d'une conversion float -> string (large marge : nos scales max = 4). */
    private const FLOAT_PRECISION = 12;

    public static function of(BigDecimal|string|int|float $value): BigDecimal
    {
        if (is_float($value)) {
            $value = self::floatToString($value);
        }

        return BigDecimal::of($value);
    }

    private static function floatToString(float $value): string
    {
        $formatted = sprintf('%.'.self::FLOAT_PRECISION.'F', $value);

        if (! str_contains($formatted, '.')) {
            return $formatted;
        }

        $trimmed = rtrim(rtrim($formatted, '0'), '.');

        return $trimmed === '' || $trimmed === '-' ? '0' : $trimmed;
    }
}
