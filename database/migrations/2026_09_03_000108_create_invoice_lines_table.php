<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ligne de facture — données BRUTES telles que facturées (M2), figées pour l'audit.
 *
 * CONCEPTION.md §2.1 :
 *   id PK · invoice_id FK · purchase_order_line_id FK? (NULLABLE) ·
 *   article_code · description · qty_invoiced(dec14,3) · unit_price(dec14,4) · timestamps
 *   └ CHECK qty_invoiced > 0, unit_price >= 0   (posé par 2026_09_03_000112_add_check_constraints)
 *
 * M2 — FK nullable : une FK nulle = article facturé absent du PO -> needs_review (règle 5).
 *   article_code / description / unit_price sont conservés tels quels même quand la FK est
 *   renseignée (on fige la donnée fournisseur ; le moteur compare, il ne recopie pas).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('invoice_id')
                ->constrained('invoices')
                ->restrictOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('purchase_order_line_id')
                ->nullable()
                ->constrained('purchase_order_lines')
                ->restrictOnUpdate()
                ->restrictOnDelete();

            $table->string('article_code');
            $table->string('description');
            $table->decimal('qty_invoiced', 14, 3);
            $table->decimal('unit_price', 14, 4);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
    }
};
