<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Database-level guards for rules that were enforced only in PHP, so a raw
 * write (tinker, an import, another client) cannot store nonsense.
 *
 * SQLite cannot add CHECK constraints to an existing table, so it gets
 * BEFORE INSERT/UPDATE triggers with the same condition; MySQL 8 and
 * Postgres get real CHECK constraints. `{c}` is the row prefix (NEW. in a
 * trigger, nothing in a CHECK).
 */
return new class extends Migration
{
    private function guards(): array
    {
        $digits = match (DB::getDriverName()) {
            'sqlite' => "{c}dni NOT GLOB '*[^0-9]*' AND length({c}dni) BETWEEN 7 AND 8",
            'pgsql' => "{c}dni ~ '^[0-9]{7,8}$'",
            default => "{c}dni REGEXP '^[0-9]{7,8}$'",
        };
        $codigo = match (DB::getDriverName()) {
            'sqlite' => "{c}codigo_localidad IS NULL OR (length({c}codigo_localidad) = 3 AND {c}codigo_localidad NOT GLOB '*[^0-9]*')",
            'pgsql' => "{c}codigo_localidad IS NULL OR {c}codigo_localidad ~ '^[0-9]{3}$'",
            default => "{c}codigo_localidad IS NULL OR {c}codigo_localidad REGEXP '^[0-9]{3}$'",
        };

        return [
            // A permanent deferral has no end; a temporary one ends on or after it starts.
            'restricciones' => ['chk_restricciones_ventana' => '({c}permanente AND {c}hasta IS NULL) OR (NOT {c}permanente AND {c}hasta IS NOT NULL AND {c}hasta >= {c}desde)'],
            'observaciones' => ['chk_observaciones_ventana' => '{c}hasta IS NULL OR {c}hasta >= {c}desde'],
            'habilitaciones_plaquetas' => ['chk_habilitaciones_ventana' => '{c}hasta >= {c}desde'],
            'turnos' => ['chk_turnos_estado' => "{c}estado IN ('PENDIENTE', 'ATENDIDO', 'CANCELADO')"],
            'recordatorios_turno' => ['chk_recordatorios_estado' => "{c}estado IN ('PENDIENTE', 'ENVIADO', 'FALLIDO')"],
            'pacientes' => [
                'chk_pacientes_sexo' => "{c}sexo IS NULL OR {c}sexo IN ('M', 'F')",
                'chk_pacientes_contadores' => '({c}ciclo_actual IS NULL OR {c}ciclo_actual >= 1) AND ({c}serie_actual IS NULL OR {c}serie_actual >= 1) AND ({c}donacion_actual IS NULL OR {c}donacion_actual >= 1)',
                'chk_pacientes_dni' => $digits,
            ],
            'donaciones' => ['chk_donaciones_ciclo' => '({c}ciclo IS NULL OR {c}ciclo >= 1) AND ({c}serie IS NULL OR {c}serie >= 1) AND ({c}donacion IS NULL OR {c}donacion >= 1)'],
            'sedes' => ['chk_sedes_codigo_localidad' => $codigo],
        ];
    }

    public function up(): void
    {
        // Normalise locality codes before guarding them ("1" -> "001").
        foreach (DB::table('sedes')->whereNotNull('codigo_localidad')->get(['id', 'codigo_localidad']) as $sede) {
            DB::table('sedes')->where('id', $sede->id)
                ->update(['codigo_localidad' => str_pad((string) $sede->codigo_localidad, 3, '0', STR_PAD_LEFT)]);
        }

        foreach ($this->guards() as $table => $checks) {
            foreach ($checks as $name => $condition) {
                if (DB::getDriverName() === 'sqlite') {
                    $when = str_replace('{c}', 'NEW.', $condition);
                    foreach (['INSERT', 'UPDATE'] as $event) {
                        DB::statement(sprintf(
                            "CREATE TRIGGER %s_%s BEFORE %s ON %s FOR EACH ROW WHEN NOT (%s) BEGIN SELECT RAISE(ABORT, 'CHECK constraint failed: %s'); END",
                            $name, strtolower($event), $event, $table, $when, $name,
                        ));
                    }
                } else {
                    DB::statement(sprintf('ALTER TABLE %s ADD CONSTRAINT %s CHECK (%s)', $table, $name, str_replace('{c}', '', $condition)));
                }
            }
        }
    }

    public function down(): void
    {
        foreach ($this->guards() as $table => $checks) {
            foreach (array_keys($checks) as $name) {
                if (DB::getDriverName() === 'sqlite') {
                    DB::statement("DROP TRIGGER IF EXISTS {$name}_insert");
                    DB::statement("DROP TRIGGER IF EXISTS {$name}_update");
                } elseif (DB::getDriverName() === 'mysql') {
                    DB::statement("ALTER TABLE {$table} DROP CHECK {$name}");
                } else {
                    DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$name}");
                }
            }
        }
    }
};
