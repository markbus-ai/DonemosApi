<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Batch delivery claims its rows as ENVIANDO before calling WhatsPlay, so the
 * recordatorios_turno estado guard (2026_10_23_000002) learns that state.
 * Same mechanics as that migration: triggers on SQLite, CHECK elsewhere, and
 * the same guard name so withoutDatabaseGuards() keeps dropping it.
 */
return new class extends Migration
{
    private const NAME = 'chk_recordatorios_estado';

    private const ESTADOS_ANTES = ['PENDIENTE', 'ENVIADO', 'FALLIDO'];

    private const ESTADOS_DESPUES = ['PENDIENTE', 'ENVIANDO', 'ENVIADO', 'FALLIDO'];

    public function up(): void
    {
        $this->replaceGuard(self::ESTADOS_DESPUES);
    }

    public function down(): void
    {
        // A row caught mid-send cannot be told apart from a sent one: fail it
        // (never back to PENDIENTE, which would send it again).
        DB::table('recordatorios_turno')->where('estado', 'ENVIANDO')->update([
            'estado' => 'FALLIDO',
            'error' => 'Envío interrumpido (rollback de migración).',
        ]);

        $this->replaceGuard(self::ESTADOS_ANTES);
    }

    /**
     * @param  array<int, string>  $estados
     */
    private function replaceGuard(array $estados): void
    {
        $condition = '{c}estado IN ('.implode(', ', array_map(fn ($e) => "'{$e}'", $estados)).')';
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            foreach (['INSERT', 'UPDATE'] as $event) {
                $trigger = self::NAME.'_'.strtolower($event);
                DB::statement("DROP TRIGGER IF EXISTS {$trigger}");
                DB::statement(sprintf(
                    "CREATE TRIGGER %s BEFORE %s ON recordatorios_turno FOR EACH ROW WHEN NOT (%s) BEGIN SELECT RAISE(ABORT, 'CHECK constraint failed: %s'); END",
                    $trigger, $event, str_replace('{c}', 'NEW.', $condition), self::NAME,
                ));
            }

            return;
        }

        DB::statement($driver === 'mysql'
            ? 'ALTER TABLE recordatorios_turno DROP CHECK '.self::NAME
            : 'ALTER TABLE recordatorios_turno DROP CONSTRAINT '.self::NAME);
        DB::statement(sprintf('ALTER TABLE recordatorios_turno ADD CONSTRAINT %s CHECK (%s)', self::NAME, str_replace('{c}', '', $condition)));
    }
};
