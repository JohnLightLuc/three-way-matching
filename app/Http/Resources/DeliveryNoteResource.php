<?php

namespace App\Http\Resources;

use App\Models\DeliveryNote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DeliveryNote
 */
class DeliveryNoteResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'purchase_order_id' => $this->purchase_order_id,
            'received_at' => $this->received_at?->toDateString(),
            'notes' => $this->notes,
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($l) => [
                'id' => $l->id,
                'purchase_order_line_id' => $l->purchase_order_line_id,
                'qty_received' => $l->qty_received,
            ])->values()),
        ];
    }
}
