<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Journal d'audit append-only (règle 3 / M5) : ni UPDATE ni DELETE en base.
 * Une révision = NOUVELLE ligne (pour match_decisions : liée par supersedes_id).
 *
 * Garde applicative — SQLite n'offre pas de moyen portable d'interdire l'UPDATE
 * au niveau base. Les suppressions de tables (migrate:fresh) passent par le
 * schema builder, pas par Eloquent : elles ne sont donc pas bloquées ici.
 */
trait AppendOnly
{
    public static function bootAppendOnly(): void
    {
        static::updating(function (Model $model): void {
            throw new RuntimeException(
                class_basename($model)." est append-only (règle 3) : pas d'UPDATE — créez une nouvelle ligne."
            );
        });

        static::deleting(function (Model $model): void {
            throw new RuntimeException(
                class_basename($model).' est append-only (règle 3) : pas de DELETE.'
            );
        });
    }
}
