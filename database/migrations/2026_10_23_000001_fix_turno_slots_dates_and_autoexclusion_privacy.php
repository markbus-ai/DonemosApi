<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Database review fixes:
 * - turnos.fecha/hora normalised to `Y-m-d` / `H:i` (the old `date` cast
 *   stored `Y-m-d H:i:s` on SQLite).
 * - Slot uniques ignore CANCELADO rows, so a cancelled slot can be re-booked;
 *   the redundant (sede_id, fecha, hora) index is dropped.
 * - Self-exclusion stays confidential: discarded components get a neutral
 *   code and the stored motivo is encrypted.
 */
return new class extends Migration
{
    private const SLOTS = [
        'turnos_slot_sede_activo' => ['sede_id', 'fecha', 'hora'],
        'turnos_slot_paciente_activo' => ['paciente_id', 'fecha', 'hora'],
    ];

    public function up(): void
    {
        DB::table('turnos')->update([
            'fecha' => DB::raw('substr(fecha, 1, 10)'),
            'hora' => DB::raw('substr(hora, 1, 5)'),
        ]);

        Schema::table('turnos', function (Blueprint $table) {
            $table->dropUnique(['sede_id', 'fecha', 'hora']);
            $table->dropUnique(['paciente_id', 'fecha', 'hora']);
            $table->dropIndex(['sede_id', 'fecha', 'hora']);
        });

        if (DB::getDriverName() === 'mysql') {
            // MySQL has no partial indexes: NULL never collides in a unique.
            Schema::table('turnos', function (Blueprint $table) {
                $table->tinyInteger('slot_activo')->nullable()->storedAs("IF(estado = 'CANCELADO', NULL, 1)");
            });
            Schema::table('turnos', function (Blueprint $table) {
                foreach (self::SLOTS as $name => $columns) {
                    $table->unique([...$columns, 'slot_activo'], $name);
                }
            });
        } else {
            foreach (self::SLOTS as $name => $columns) {
                DB::statement(sprintf(
                    "CREATE UNIQUE INDEX %s ON turnos (%s) WHERE estado <> 'CANCELADO'",
                    $name,
                    implode(', ', $columns),
                ));
            }
        }

        Schema::table('turnos', function (Blueprint $table) {
            // Capacity sums and the report filter by sede, day and state.
            $table->index(['sede_id', 'fecha', 'estado']);
        });

        Schema::table('autoexclusiones', function (Blueprint $table) {
            $table->text('motivo')->nullable()->change();
        });

        foreach (DB::table('autoexclusiones')->whereNotNull('motivo')->get(['id', 'motivo', 'donacion_id']) as $row) {
            DB::table('autoexclusiones')->where('id', $row->id)->update(['motivo' => Crypt::encryptString($row->motivo)]);
        }

        DB::table('componentes_donacion')
            ->whereIn('donacion_id', DB::table('autoexclusiones')->select('donacion_id'))
            ->update(['motivo_descarte' => 'DESCARTE_CONFIDENCIAL']);
    }

    public function down(): void
    {
        Schema::table('turnos', function (Blueprint $table) {
            $table->dropIndex(['sede_id', 'fecha', 'estado']);
        });

        if (DB::getDriverName() === 'mysql') {
            Schema::table('turnos', function (Blueprint $table) {
                foreach (array_keys(self::SLOTS) as $name) {
                    $table->dropUnique($name);
                }
                $table->dropColumn('slot_activo');
            });
        } else {
            foreach (array_keys(self::SLOTS) as $name) {
                DB::statement("DROP INDEX {$name}");
            }
        }

        Schema::table('turnos', function (Blueprint $table) {
            $table->index(['sede_id', 'fecha', 'hora']);
            $table->unique(['sede_id', 'fecha', 'hora']);
            $table->unique(['paciente_id', 'fecha', 'hora']);
        });

        // Encrypted motivos and neutral discard codes are kept: decrypting on
        // rollback would re-expose confidential data.
    }
};
