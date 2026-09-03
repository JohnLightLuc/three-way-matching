<?php

namespace App\Models;

use Brick\Math\BigDecimal;
use Database\Factories\PurchaseOrderLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ligne de PO — SOURCE DE VÉRITÉ UNIQUE (règle 1 / M1) : quantité et prix « justes ».
 *
 * qty_reçue / qty_rapprochée ne sont PAS stockées (règle 7 / M4) : ce sont des
 * agrégations — Σ delivery_note_lines ; Σ authorized_qty des décisions COURANTES.
 * Calculées à la volée (une requête chacune ; cache possible plus tard — M4).
 */
#[Fillable([
    'purchase_order_id',
    'line_no',
    'article_code',
    'description',
    'unit',
    'qty_ordered',
    'unit_price',
])]
class PurchaseOrderLine extends Model
{
    /** @use HasFactory<PurchaseOrderLineFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'qty_ordered' => 'decimal:3', // règle 2
            'unit_price' => 'decimal:4',  // règle 2
        ];
    }

    /** @return BelongsTo<PurchaseOrder, $this> */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /** @return HasMany<DeliveryNoteLine, $this> */
    public function deliveryNoteLines(): HasMany
    {
        return $this->hasMany(DeliveryNoteLine::class);
    }

    /** @return HasMany<InvoiceLine, $this> */
    public function invoiceLines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    /** @return HasMany<MatchDecision, $this> */
    public function matchDecisions(): HasMany
    {
        return $this->hasMany(MatchDecision::class);
    }

    /* ----------------------------------------------------------------- */
    /*  Agrégations (règle 7) — jamais stockées */
    /* ----------------------------------------------------------------- */

    /** Σ des quantités reçues sur cette ligne (tous DN confondus). */
    public function receivedQty(): BigDecimal
    {
        return $this->sumDecimal($this->deliveryNoteLines()->pluck('qty_received')->all());
    }

    /** Σ des authorized_qty des décisions COURANTES rattachées à cette ligne. */
    public function matchedQty(): BigDecimal
    {
        $values = MatchDecision::query()
            ->current()
            ->whereHas('invoiceLine', fn (Builder $q) => $q->where('purchase_order_line_id', $this->getKey()))
            ->pluck('authorized_qty')
            ->all();

        return $this->sumDecimal($values);
    }

    /** Quantité commandée encore disponible pour rapprochement (jamais négative). */
    public function availableQty(): BigDecimal
    {
        $available = BigDecimal::of($this->qty_ordered)->minus($this->matchedQty());

        return ($available->isNegative() ? BigDecimal::zero() : $available)->toScale(3);
    }

    /** @param  array<int, string|int|float>  $values */
    private function sumDecimal(array $values): BigDecimal
    {
        $total = BigDecimal::zero();

        foreach ($values as $value) {
            $total = $total->plus(BigDecimal::of((string) $value));
        }

        return $total->toScale(3);
    }
}
