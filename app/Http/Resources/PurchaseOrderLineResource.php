<?php

namespace App\Http\Resources;

use App\Models\PurchaseOrderLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PurchaseOrderLine
 */
class PurchaseOrderLineResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'line_no' => $this->line_no,
            'article_code' => $this->article_code,
            'description' => $this->description,
            'unit' => $this->unit,
            'qty_ordered' => $this->qty_ordered,
            'unit_price' => $this->unit_price,
            // Agrégations (règle 7) — jamais stockées.
            'qty_received' => (string) $this->receivedQty(),
            'qty_matched' => (string) $this->matchedQty(),
            'qty_available' => (string) $this->availableQty(),
        ];
    }
}
