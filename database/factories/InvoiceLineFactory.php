<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\PurchaseOrderLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceLine>
 *
 * Données brutes telles que facturées (M2). Par défaut la ligne « colle » à sa
 * ligne de PO (article/prix/quantité repris) : cas sain. Des états dédiés
 * fabriquent les écarts (prix, sur-facturation, article hors PO).
 */
class InvoiceLineFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'purchase_order_line_id' => PurchaseOrderLine::factory(),
            'article_code' => 'ART-'.fake()->unique()->numerify('#####'),
            'description' => fake()->sentence(3),
            'qty_invoiced' => fake()->numberBetween(1, 100),
            'unit_price' => fake()->randomFloat(4, 1, 100),
        ];
    }

    /**
     * Ligne conforme à une ligne de PO : FK posée, article/description/prix repris
     * du PO, quantité facturée = $qty (par défaut, toute la quantité commandée).
     */
    public function forPoLine(PurchaseOrderLine $line, int|float|string|null $qty = null): static
    {
        return $this->state([
            'purchase_order_line_id' => $line->id,
            'article_code' => $line->article_code,
            'description' => $line->description,
            'qty_invoiced' => $qty ?? $line->qty_ordered,
            'unit_price' => $line->unit_price,
        ]);
    }

    /** Prix facturé décalé de $pct (ex. 0.03 = +3 %) par rapport au prix PO. */
    public function priceDeviation(PurchaseOrderLine $line, float $pct): static
    {
        return $this->state([
            'unit_price' => round((float) $line->unit_price * (1 + $pct), 4),
        ]);
    }

    /** Article facturé absent du PO : FK nulle + code/prix bruts (M2 -> needs_review). */
    public function offPo(): static
    {
        return $this->state([
            'purchase_order_line_id' => null,
            'article_code' => 'HORS-PO-'.fake()->unique()->numerify('####'),
            'description' => 'Article non commandé',
        ]);
    }

    /** Fixe la quantité facturée. */
    public function qty(int|float|string $qty): static
    {
        return $this->state(['qty_invoiced' => $qty]);
    }
}
