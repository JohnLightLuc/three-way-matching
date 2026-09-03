<?php

namespace App\Models;

use Database\Factories\DeliveryNoteLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ligne de DN : quantité réellement reçue pour une ligne de PO donnée.
 * FK vers la ligne de PO OBLIGATOIRE (M2). Invariant applicatif : la ligne de PO
 * doit appartenir au même PO que le DN (garanti par la factory / le service).
 */
#[Fillable(['delivery_note_id', 'purchase_order_line_id', 'qty_received'])]
class DeliveryNoteLine extends Model
{
    /** @use HasFactory<DeliveryNoteLineFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'qty_received' => 'decimal:3', // règle 2
        ];
    }

    /** @return BelongsTo<DeliveryNote, $this> */
    public function deliveryNote(): BelongsTo
    {
        return $this->belongsTo(DeliveryNote::class);
    }

    /** @return BelongsTo<PurchaseOrderLine, $this> */
    public function purchaseOrderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class);
    }

    /** @return HasMany<MatchDecisionConsumption, $this> */
    public function consumptions(): HasMany
    {
        return $this->hasMany(MatchDecisionConsumption::class);
    }
}
