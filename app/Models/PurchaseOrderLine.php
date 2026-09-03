<?php

namespace App\Models;

use Database\Factories\PurchaseOrderLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ligne de PO — SOURCE DE VÉRITÉ UNIQUE (règle 1 / M1) : quantité et prix « justes ».
 *
 * qty_reçue / qty_rapprochée ne sont PAS stockées ici (règle 7 / M4) : ce sont des
 * agrégations (Σ delivery_note_lines ; Σ autorisations courantes). Les accesseurs
 * correspondants seront ajoutés avec le moteur (étape 3b).
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
}
