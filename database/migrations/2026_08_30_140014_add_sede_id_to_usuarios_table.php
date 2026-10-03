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

        Schema::table('usuarios', function (Blueprint $table) {
            // NULL means global super-admin; scoped staff always carry a sede.
            $table->foreignId('sede_id')->nullable()->constrained('sedes')->restrictOnDelete();
            $table->index(['sede_id', 'rol_id']);
        });

        // Backfill legacy rows to the default site.
        DB::table('usuarios')->whereNull('sede_id')->update(['sede_id' => $defaultId]);
    }

    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->dropIndex(['sede_id', 'rol_id']);
            $table->dropForeign(['sede_id']);
            $table->dropColumn('sede_id');
        });
    }
};
