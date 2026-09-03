<?php

namespace App\Http\Resources;

use App\Models\InvoiceLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin InvoiceLine
 */
class InvoiceLineResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'purchase_order_line_id' => $this->purchase_order_line_id,
            'article_code' => $this->article_code,
            'description' => $this->description,
            'qty_invoiced' => $this->qty_invoiced,
            'unit_price' => $this->unit_price,
            'decision' => $this->whenLoaded(
                'currentMatchDecision',
                fn () => $this->currentMatchDecision
                    ? new MatchDecisionResource($this->currentMatchDecision)
                    : null,
            ),
            'payment_authorization' => $this->whenLoaded(
                'paymentAuthorization',
                fn () => $this->paymentAuthorization ? [
                    'authorized_qty' => $this->paymentAuthorization->authorized_qty,
                    'authorized_amount' => $this->paymentAuthorization->authorized_amount,
                    'status' => $this->paymentAuthorization->status,
                ] : null,
            ),
        ];
    }
}
