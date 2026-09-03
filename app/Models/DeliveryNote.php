<?php

namespace App\Models;

use Database\Factories\DeliveryNoteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Bon de livraison (DN). Rattaché à un PO ; plusieurs DN par PO, livraisons partielles (F2).
 * received_at porte l'ordre FIFO de consommation (M10).
 */
#[Fillable(['reference', 'purchase_order_id', 'received_at', 'notes'])]
class DeliveryNote extends Model
{
    /** @use HasFactory<DeliveryNoteFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'received_at' => 'date',
        ];
    }

    /** @return BelongsTo<PurchaseOrder, $this> */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /** @return HasMany<DeliveryNoteLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(DeliveryNoteLine::class);
    }
}
