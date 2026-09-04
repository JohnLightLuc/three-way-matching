<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Journal d'activité — traçabilité de TOUTES les actions mutantes de l'API
 * (POST/PUT/PATCH/DELETE) + tentatives de login (succès et échec).
 *
 * Distinct de match_decisions, qui reste l'audit DÉCISIONNEL faisant foi.
 * Écrit par le middleware terminable App\Http\Middleware\RecordActivity.
 *
 * - user_id NULL          : requête non authentifiée (login, ou 401).
 * - payload_digest        : sha256 du corps de requête ASSAINI (password / token
 *   caviardés) — on trace sans jamais stocker de secret ni de PII massive.
 * - created_at seul       : journal append-only (pas d'updated_at).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->restrictOnUpdate()
                ->nullOnDelete();

            $table->string('method', 10);
            $table->string('route');
            $table->string('target_type')->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('payload_digest', 64)->nullable();
            $table->unsignedSmallInteger('status_code');
            $table->timestamp('created_at')->nullable();

            $table->index(['target_type', 'target_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
