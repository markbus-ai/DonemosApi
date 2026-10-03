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

        Schema::table('donaciones', function (Blueprint $table) use ($defaultId) {
            // NOT NULL with a database default so pre-sede writes keep working.
            // New writes should pass sede_id explicitly.
            $table->foreignId('sede_id')->default($defaultId)->constrained('sedes')->restrictOnDelete();
            $table->index(['sede_id', 'fecha']);
        });

        // Backfill legacy rows to the default site.
        DB::table('donaciones')->whereNull('sede_id')->update(['sede_id' => $defaultId]);
    }

    public function down(): void
    {
        Schema::table('donaciones', function (Blueprint $table) {
            $table->dropIndex(['sede_id', 'fecha']);
            $table->dropForeign(['sede_id']);
            $table->dropColumn('sede_id');
        });
    }
};
