<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ligne de bon de commande — SOURCE DE VÉRITÉ UNIQUE (règle 1 / M1).
 * DN et factures s'y raccrochent par FK ; jamais de lien direct DN↔facture.
 *
 * CONCEPTION.md §2.1 :
 *   id PK · purchase_order_id FK · line_no(int) · article_code · description ·
 *   unit · qty_ordered(dec14,3) · unit_price(dec14,4) · timestamps
 *   └ UNIQUE(purchase_order_id, line_no)
 *   └ CHECK qty_ordered > 0, unit_price >= 0   (posé par 2026_09_03_000112_add_check_constraints)
 *
 * Règle 2 — décimaux : qty en 3 déc., prix unitaire en 4 déc. Jamais de float.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('purchase_order_id')
                ->constrained('purchase_orders')
                ->restrictOnUpdate()
                ->restrictOnDelete();

            $table->unsignedInteger('line_no');
            $table->string('article_code');
            $table->string('description');
            $table->string('unit');
            $table->decimal('qty_ordered', 14, 3);
            $table->decimal('unit_price', 14, 4);
            $table->timestamps();

            $table->unique(['purchase_order_id', 'line_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_lines');
    }
};
