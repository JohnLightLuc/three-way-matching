<?php

namespace App\Models;

use Database\Factories\InvoiceLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Ligne de facture — données BRUTES telles que facturées (M2), figées pour l'audit.
 * purchase_order_line_id NULLABLE : une FK nulle = article hors PO -> needs_review (règle 5).
 */
#[Fillable([
    'invoice_id',
    'purchase_order_line_id',
    'article_code',
    'description',
    'qty_invoiced',
    'unit_price',
])]
class InvoiceLine extends Model
{
    /** @use HasFactory<InvoiceLineFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'qty_invoiced' => 'decimal:3', // règle 2
            'unit_price' => 'decimal:4',   // règle 2 (prix facturé — comparé, jamais utilisé pour autoriser)
        ];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<PurchaseOrderLine, $this> */
    public function purchaseOrderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class);
    }

    /** Historique des évaluations moteur pour cette ligne (append-only). @return HasMany<MatchDecision, $this> */
    public function matchDecisions(): HasMany
    {
        return $this->hasMany(MatchDecision::class);
    }

    /** Décision COURANTE (celle qu'aucune autre ne remplace). @return HasOne<MatchDecision, $this> */
    public function currentMatchDecision(): HasOne
    {
        return $this->hasOne(MatchDecision::class)
            ->whereDoesntHave('supersededBy')
            ->latestOfMany();
    }

    /** Autorisation de paiement courante (0 ou 1 active). @return HasOne<PaymentAuthorization, $this> */
    public function paymentAuthorization(): HasOne
    {
        return $this->hasOne(PaymentAuthorization::class)->where('status', 'authorized');
    }
}
