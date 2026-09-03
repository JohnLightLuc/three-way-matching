<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Database\Factories\MatchDecisionConsumptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trace « décision ↔ ligne de DN consommée » + quantité imputée (M9). Append-only.
 *
 * Invariants (règle 6 / M9) :
 *   - Σ qty_consumed d'une décision      = son authorized_qty ;
 *   - Σ qty_consumed sur une ligne de DN ≤ sa qty_received (décisions autorisées seulement).
 */
#[Fillable(['match_decision_id', 'delivery_note_line_id', 'qty_consumed'])]
class MatchDecisionConsumption extends Model
{
    /** @use HasFactory<MatchDecisionConsumptionFactory> */
    use AppendOnly;

    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'qty_consumed' => 'decimal:3', // règle 2
        ];
    }

    /** @return BelongsTo<MatchDecision, $this> */
    public function matchDecision(): BelongsTo
    {
        return $this->belongsTo(MatchDecision::class);
    }

    /** @return BelongsTo<DeliveryNoteLine, $this> */
    public function deliveryNoteLine(): BelongsTo
    {
        return $this->belongsTo(DeliveryNoteLine::class);
    }
}
