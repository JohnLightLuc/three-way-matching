<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ligne de bon de livraison. FK vers la ligne de PO OBLIGATOIRE (M2 : réception interne).
 *
 * CONCEPTION.md §2.1 :
 *   id PK · delivery_note_id FK · purchase_order_line_id FK · qty_received(dec14,3) · timestamps
 *   └ CHECK qty_received > 0   (posé par 2026_09_03_000112_add_check_constraints)
 *   └ invariant applicatif : la ligne de PO doit appartenir au même PO que le DN
 *     (non exprimable en SQL portable — vérifié côté service/factory, pas ici).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_note_lines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('delivery_note_id')
                ->constrained('delivery_notes')
                ->restrictOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('purchase_order_line_id')
                ->constrained('purchase_order_lines')
                ->restrictOnUpdate()
                ->restrictOnDelete();

            $table->decimal('qty_received', 14, 3);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_note_lines');
    }
};
