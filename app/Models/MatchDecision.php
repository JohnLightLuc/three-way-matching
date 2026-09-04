<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Database\Factories\MatchDecisionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Décision du moteur — ligne de JOURNAL D'AUDIT append-only (règle 3 / M5).
 * Jamais d'UPDATE/DELETE ; une révision humaine = nouvelle ligne (actor_type=user)
 * liée par supersedes_id à la décision remplacée (F10).
 *
 * authorized_amount = authorized_qty × prix PO (règle 4), jamais le prix facturé.
 * inputs_snapshot fige tout ce qui rend la décision reproductible (règle 10 / M6).
 */
#[Fillable([
    'invoice_line_id',
    'purchase_order_line_id',
    'status',
    'matchable_qty',
    'authorized_qty',
    'authorized_amount',
    'price_delta_pct',
    'reasons',
    'actor_type',
    'actor_user_id',
    'decided_at',
    'inputs_snapshot',
    'supersedes_id',
])]
class MatchDecision extends Model
{
    /** @use HasFactory<MatchDecisionFactory> */
    use AppendOnly;

    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'matchable_qty' => 'decimal:3',    // règle 2
            'authorized_qty' => 'decimal:3',   // règle 2
            'authorized_amount' => 'decimal:2', // règle 2
            'price_delta_pct' => 'decimal:4',
            'reasons' => 'array',
            'inputs_snapshot' => 'array',
            'decided_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<InvoiceLine, $this> */
    public function invoiceLine(): BelongsTo
    {
        return $this->belongsTo(InvoiceLine::class);
    }

    /** Réviseur humain à l'origine de la décision ; null quand actor_type = system. @return BelongsTo<User, $this> */
    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /** @return BelongsTo<PurchaseOrderLine, $this> */
    public function purchaseOrderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class);
    }

    /** Décision que celle-ci remplace (révision humaine). @return BelongsTo<MatchDecision, $this> */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(MatchDecision::class, 'supersedes_id');
    }

    /** Décision(s) qui remplacent celle-ci (0 ou 1). @return HasMany<MatchDecision, $this> */
    public function supersededBy(): HasMany
    {
        return $this->hasMany(MatchDecision::class, 'supersedes_id');
    }

    /**
     * Décisions COURANTES : celles qu'aucune autre ne remplace (règle 7 / M10).
     * Une décision superseded a libéré son allocation et sort du pool.
     *
     * @param  Builder<MatchDecision>  $query
     */
    public function scopeCurrent(Builder $query): void
    {
        $query->whereDoesntHave('supersededBy');
    }

    /** DN imputés en FIFO par cette décision. @return HasMany<MatchDecisionConsumption, $this> */
    public function consumptions(): HasMany
    {
        return $this->hasMany(MatchDecisionConsumption::class);
    }

    /** @return HasMany<PaymentAuthorization, $this> */
    public function paymentAuthorizations(): HasMany
    {
        return $this->hasMany(PaymentAuthorization::class);
    }
}
