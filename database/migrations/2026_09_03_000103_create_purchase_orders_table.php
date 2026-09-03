<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bon de commande (PO) — l'ancre du modèle.
 *
 * CONCEPTION.md §2.1 :
 *   id PK · reference UK · supplier_id FK→suppliers · project_id FK→projects ·
 *   status(draft|open|closed|cancelled = open) · currency(char3 = XOF) · notes? · timestamps.
 *
 * M8 — FK en RESTRICT (on ne supprime pas ce qui est audité).
 * M8 — enums stockés en string (portabilité SQLite/MySQL/PostgreSQL).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();

            $table->foreignId('supplier_id')
                ->constrained('suppliers')
                ->restrictOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('project_id')
                ->constrained('projects')
                ->restrictOnUpdate()
                ->restrictOnDelete();

            $table->string('status')->default('open'); // draft|open|closed|cancelled
            $table->char('currency', 3)->default('XOF');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};
