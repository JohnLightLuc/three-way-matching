<?php

namespace App\Http\Resources;

use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Invoice
 */
class InvoiceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status,
            'purchase_order_id' => $this->purchase_order_id,
            'supplier_id' => $this->supplier_id,
            'invoice_date' => $this->invoice_date?->toDateString(),
            'currency' => $this->currency,
            'notes' => $this->notes,
            'purchase_order' => $this->whenLoaded('purchaseOrder', fn () => [
                'id' => $this->purchaseOrder->id,
                'reference' => $this->purchaseOrder->reference,
            ]),
            'lines_count' => $this->whenCounted('lines'),
            'lines' => InvoiceLineResource::collection($this->whenLoaded('lines')),
        ];
    }
}
