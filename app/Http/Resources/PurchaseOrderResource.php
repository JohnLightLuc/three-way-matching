<?php

namespace App\Http\Resources;

use App\Models\PurchaseOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PurchaseOrder
 */
class PurchaseOrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status,
            'currency' => $this->currency,
            'notes' => $this->notes,
            'supplier' => $this->whenLoaded('supplier', fn () => [
                'id' => $this->supplier->id,
                'code' => $this->supplier->code,
                'name' => $this->supplier->name,
            ]),
            'project' => $this->whenLoaded('project', fn () => [
                'id' => $this->project->id,
                'code' => $this->project->code,
                'name' => $this->project->name,
            ]),
            'lines' => PurchaseOrderLineResource::collection($this->whenLoaded('lines')),
            'delivery_notes' => DeliveryNoteResource::collection($this->whenLoaded('deliveryNotes')),
            'invoices' => $this->whenLoaded('invoices', fn () => $this->invoices->map(fn ($i) => [
                'id' => $i->id,
                'reference' => $i->reference,
                'status' => $i->status,
            ])->values()),
        ];
    }
}
