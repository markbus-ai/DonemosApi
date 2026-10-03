<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Resolve the default site (created by the sedes migration).
        $defaultId = DB::table('sedes')->where('nombre', 'Sede Central')->value('id');

        if ($defaultId === null) {
            $defaultId = DB::table('sedes')->insertGetId([
                'nombre' => 'Sede Central',
                'direccion' => null,
                'telefono' => null,
                'activa' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('turnos', function (Blueprint $table) use ($defaultId) {
            // NOT NULL with a database default so pre-sede writes keep working.
            // New writes should pass sede_id explicitly.
            $table->foreignId('sede_id')->default($defaultId)->constrained('sedes')->restrictOnDelete();
            $table->index(['sede_id', 'fecha', 'hora']);
            // One booking per slot within each site.
            $table->unique(['sede_id', 'fecha', 'hora']);
            // Block the same patient double-booking the same slot across sites.
            $table->unique(['paciente_id', 'fecha', 'hora']);
        });

        // Backfill legacy rows to the default site.
        DB::table('turnos')->whereNull('sede_id')->update(['sede_id' => $defaultId]);
    }

    public function down(): void
    {
        Schema::table('turnos', function (Blueprint $table) {
            $table->dropUnique(['sede_id', 'fecha', 'hora']);
            $table->dropUnique(['paciente_id', 'fecha', 'hora']);
            $table->dropIndex(['sede_id', 'fecha', 'hora']);
            $table->dropForeign(['sede_id']);
            $table->dropColumn('sede_id');
        });
    }
};
