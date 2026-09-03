<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tolérances de rapprochement à 3 voies
    |--------------------------------------------------------------------------
    |
    | Seuils globaux appliqués par le moteur (ThreeWayMatcher). Ils ne sont
    | lus QUE via cette config — jamais en dur ailleurs (CLAUDE.md). Chaque
    | décision fige la valeur effective dans son inputs_snapshot (règle 10 /
    | M6) : une décision passée reste reproductible même si le seuil change.
    |
    | - price_tolerance_pct : écart relatif toléré entre prix facturé et prix
    |   PO (0.01 = 1 %). Au-delà -> needs_review, on autorise 0.
    | - qty_tolerance_abs   : écart absolu toléré sur les quantités (0.0 =
    |   aucune tolérance ; facturé > commandé -> needs_review).
    |
    */

    'price_tolerance_pct' => (float) env('MATCHING_PRICE_TOLERANCE_PCT', 0.01),

    'qty_tolerance_abs' => (float) env('MATCHING_QTY_TOLERANCE_ABS', 0.0),

];
