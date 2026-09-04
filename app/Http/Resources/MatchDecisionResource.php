<?php

namespace App\Http\Resources;

use App\Models\MatchDecision;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MatchDecision
 */
class MatchDecisionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_line_id' => $this->invoice_line_id,
            'purchase_order_line_id' => $this->purchase_order_line_id,
            'status' => $this->status,
            'matchable_qty' => $this->matchable_qty,
            'authorized_qty' => $this->authorized_qty,
            'authorized_amount' => $this->authorized_amount,
            'price_delta_pct' => $this->price_delta_pct,
            'reasons' => $this->reasons,
            'actor_type' => $this->actor_type,
            'actor_user_id' => $this->actor_user_id,
            'actor_user' => $this->whenLoaded('actorUser', fn () => $this->actorUser ? [
                'id' => $this->actorUser->id,
                'name' => $this->actorUser->name,
            ] : null),
            'supersedes_id' => $this->supersedes_id,
            'is_current' => $this->when(
                $this->relationLoaded('supersededBy'),
                fn (): bool => $this->supersededBy->isEmpty(),
            ),
            'decided_at' => $this->decided_at?->toIso8601String(),
            'invoice_line' => $this->whenLoaded('invoiceLine', fn () => [
                'id' => $this->invoiceLine->id,
                'article_code' => $this->invoiceLine->article_code,
                'qty_invoiced' => $this->invoiceLine->qty_invoiced,
                'unit_price' => $this->invoiceLine->unit_price,
                'invoice' => $this->invoiceLine->relationLoaded('invoice') && $this->invoiceLine->invoice ? [
                    'id' => $this->invoiceLine->invoice->id,
                    'reference' => $this->invoiceLine->invoice->reference,
                ] : null,
            ]),
            'consumptions' => $this->whenLoaded('consumptions', fn () => $this->consumptions->map(fn ($c) => [
                'delivery_note_line_id' => $c->delivery_note_line_id,
                'qty_consumed' => $c->qty_consumed,
            ])->values()),
        ];
    }
}
