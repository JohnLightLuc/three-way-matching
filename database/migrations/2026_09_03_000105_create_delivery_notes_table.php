<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bon de livraison (DN). Plusieurs DN par PO ; livraisons partielles permises (F2).
 *
 * CONCEPTION.md §2.1 :
 *   id PK · reference UK · purchase_order_id FK · received_at(date) · notes? · timestamps
 *
 * received_at porte l'ordre FIFO de consommation (M10).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_notes', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();

            $table->foreignId('purchase_order_id')
                ->constrained('purchase_orders')
                ->restrictOnUpdate()
                ->restrictOnDelete();

            $table->date('received_at');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_notes');
    }
};
