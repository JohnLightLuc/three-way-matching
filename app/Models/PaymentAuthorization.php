<?php

namespace App\Models;

use Database\Factories\PaymentAuthorizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registre d'allocation COURANT (M5) — projection de la dernière décision autorisée.
 * Anti double-paiement (F7) : une seule ligne status='authorized' par invoice_line_id
 * (index unique partiel SQLite/PostgreSQL ; garde service sous MySQL).
 *
 * Contrairement à match_decisions, PA n'est PAS append-only : status passe de
 * 'authorized' à 'revoked' quand une décision est remplacée (M10 : libère l'allocation).
 */
#[Fillable([
    'invoice_line_id',
    'match_decision_id',
    'authorized_qty',
    'authorized_amount',
    'status',
])]
class PaymentAuthorization extends Model
{
    /** @use HasFactory<PaymentAuthorizationFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'authorized_qty' => 'decimal:3',    // règle 2
            'authorized_amount' => 'decimal:2', // règle 2
        ];
    }

    /** @return BelongsTo<InvoiceLine, $this> */
    public function invoiceLine(): BelongsTo
    {
        return $this->belongsTo(InvoiceLine::class);
    }

    /** @return BelongsTo<MatchDecision, $this> */
    public function matchDecision(): BelongsTo
    {
        return $this->belongsTo(MatchDecision::class);
    }
}
