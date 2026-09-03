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
            'supersedes_id' => $this->supersedes_id,
            'decided_at' => $this->decided_at?->toIso8601String(),
            'consumptions' => $this->whenLoaded('consumptions', fn () => $this->consumptions->map(fn ($c) => [
                'delivery_note_line_id' => $c->delivery_note_line_id,
                'qty_consumed' => $c->qty_consumed,
            ])->values()),
        ];
    }
}
