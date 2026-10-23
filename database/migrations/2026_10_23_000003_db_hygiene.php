<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Database review hygiene:
 * - the default sede is a flag, not the literal name "Sede Central";
 * - staff users can be soft-disabled (`activo`) instead of deleted, since
 *   donations restrict deleting their operator;
 * - a normalised search column makes patient search case- and
 *   accent-insensitive on every driver;
 * - indexes for the real query paths (SQLite/Postgres do not index FKs);
 * - the unused framework `users` / `password_reset_tokens` tables go away.
 */
return new class extends Migration
{
    private const INDEXES = [
        'donaciones' => [['paciente_id', 'fecha']],
        'observaciones' => [['paciente_id']],
        'autorizaciones_extraordinarias' => [['paciente_id']],
        'recordatorios_turno' => [['turno_id']],
        'componentes_donacion' => [['tipo_id']],
        'pacientes' => [['busqueda']],
    ];

    public function up(): void
    {
        Schema::table('sedes', function (Blueprint $table) {
            $table->boolean('es_default')->default(false);
        });
        $defaultId = DB::table('sedes')->where('nombre', 'Sede Central')->value('id')
            ?? DB::table('sedes')->orderBy('id')->value('id');
        if ($defaultId !== null) {
            DB::table('sedes')->where('id', $defaultId)->update(['es_default' => true]);
        }

        Schema::table('usuarios', function (Blueprint $table) {
            $table->boolean('activo')->default(true);
        });

        Schema::table('pacientes', function (Blueprint $table) {
            $table->string('busqueda')->nullable();
        });
        foreach (DB::table('pacientes')->get(['id', 'dni', 'nombre', 'apellido']) as $paciente) {
            DB::table('pacientes')->where('id', $paciente->id)->update([
                'busqueda' => Str::lower(Str::ascii(trim("{$paciente->dni} {$paciente->nombre} {$paciente->apellido}"))),
            ]);
        }

        foreach (self::INDEXES as $table => $indexes) {
            foreach ($indexes as $columns) {
                if (! Schema::hasIndex($table, $columns)) {
                    Schema::table($table, fn (Blueprint $t) => $t->index($columns));
                }
            }
        }

        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }

    public function down(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::table('pacientes', fn (Blueprint $t) => $t->dropIndex(['busqueda']));
        Schema::table('pacientes', fn (Blueprint $t) => $t->dropColumn('busqueda'));
        Schema::table('usuarios', fn (Blueprint $t) => $t->dropColumn('activo'));
        Schema::table('sedes', fn (Blueprint $t) => $t->dropColumn('es_default'));
        // The FK indexes are harmless to keep and may predate this migration.
    }
};
