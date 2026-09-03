<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contraintes CHECK au niveau BASE sur les colonnes décimales (CONCEPTION.md §2.1) :
 *   purchase_order_lines        : qty_ordered  > 0 , unit_price >= 0
 *   delivery_note_lines         : qty_received > 0
 *   invoice_lines               : qty_invoiced > 0 , unit_price >= 0
 *   match_decision_consumptions : qty_consumed > 0
 *
 * Pas de helper fluent pour CHECK en Laravel : on passe par du SQL brut, par driver.
 *   - MySQL 8.0.16+ / PostgreSQL : ALTER TABLE ... ADD CONSTRAINT ... CHECK (...).
 *   - SQLite : n'accepte pas ADD CONSTRAINT -> on reconstruit la table avec les CHECK
 *     inline. Cette migration tourne juste après la création : sous `migrate:fresh` les
 *     tables sont VIDES, donc DROP + CREATE enrichi suffit (aucune recopie de données ;
 *     le DROP d'un parent vide est sans effet sur les FK enfants). Les index explicites
 *     (uniques, etc.) sont relus depuis sqlite_master et rejoués après reconstruction.
 */
return new class extends Migration
{
    /** @var array<string, array<string, string>>  table => [nom_contrainte => expression] */
    private array $checks = [
        'purchase_order_lines' => [
            'purchase_order_lines_qty_ordered_check' => 'qty_ordered > 0',
            'purchase_order_lines_unit_price_check' => 'unit_price >= 0',
        ],
        'delivery_note_lines' => [
            'delivery_note_lines_qty_received_check' => 'qty_received > 0',
        ],
        'invoice_lines' => [
            'invoice_lines_qty_invoiced_check' => 'qty_invoiced > 0',
            'invoice_lines_unit_price_check' => 'unit_price >= 0',
        ],
        'match_decision_consumptions' => [
            'match_decision_consumptions_qty_consumed_check' => 'qty_consumed > 0',
        ],
    ];

    public function up(): void
    {
        if ($this->isSqlite()) {
            $this->rebuildSqliteTables(fn (string $create, array $constraints) => $this->withChecks($create, $constraints));

            return;
        }

        foreach ($this->checks as $table => $constraints) {
            foreach ($constraints as $name => $expr) {
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expr})");
            }
        }
    }

    public function down(): void
    {
        if ($this->isSqlite()) {
            $this->rebuildSqliteTables(fn (string $create, array $constraints) => $this->withoutChecks($create, $constraints));

            return;
        }

        foreach ($this->checks as $table => $constraints) {
            foreach (array_keys($constraints) as $name) {
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$name}");
            }
        }
    }

    private function isSqlite(): bool
    {
        return Schema::getConnection()->getDriverName() === 'sqlite';
    }

    /**
     * Reconstruit chaque table contrainte : lit son CREATE + ses index explicites,
     * la DROP, la recrée via $transform, puis rejoue les index.
     *
     * @param  callable(string, array<string, string>): string  $transform
     */
    private function rebuildSqliteTables(callable $transform): void
    {
        Schema::disableForeignKeyConstraints(); // no-op si la migration tourne en transaction ; sûr car tables vides

        try {
            foreach ($this->checks as $table => $constraints) {
                $create = DB::selectOne(
                    "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?",
                    [$table]
                )->sql;

                $indexes = DB::select(
                    "SELECT sql FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND sql IS NOT NULL",
                    [$table]
                );

                DB::statement("DROP TABLE \"{$table}\"");
                DB::statement($transform($create, $constraints));

                foreach ($indexes as $index) {
                    DB::statement($index->sql);
                }
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    /** Injecte  , constraint "x" check (...)  avant la parenthèse fermante du CREATE TABLE. */
    private function withChecks(string $create, array $constraints): string
    {
        $additions = collect($constraints)
            ->map(fn (string $expr, string $name) => "constraint \"{$name}\" check ({$expr})")
            ->implode(', ');

        $pos = strrpos($create, ')');

        return substr($create, 0, $pos).', '.$additions.substr($create, $pos);
    }

    /** Retire les fragments  , constraint "x" check (...)  ajoutés par withChecks(). */
    private function withoutChecks(string $create, array $constraints): string
    {
        foreach (array_keys($constraints) as $name) {
            $create = preg_replace(
                '/,\s*constraint\s+"'.preg_quote($name, '/').'"\s+check\s*\([^)]*\)/i',
                '',
                $create
            );
        }

        return $create;
    }
};
