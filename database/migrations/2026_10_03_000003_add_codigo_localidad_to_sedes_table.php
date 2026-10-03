<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Locality code used to build numero_donacion.
 *
 * Nullable on purpose: an unconfigured sede must be able to fail closed
 * instead of guessing a code. UNIQUE keeps codes unambiguous.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sedes', function (Blueprint $table) {
            $table->string('codigo_localidad')->nullable()->after('nombre');
        });

        $this->backfill();

        Schema::table('sedes', function (Blueprint $table) {
            $table->unique('codigo_localidad');
        });
    }

    public function down(): void
    {
        Schema::table('sedes', function (Blueprint $table) {
            $table->dropUnique(['codigo_localidad']);
        });

        Schema::table('sedes', function (Blueprint $table) {
            $table->dropColumn('codigo_localidad');
        });
    }

    /**
     * The central sede is the database default for donations, so it must carry
     * a code or the load-bearing donation flow would fail closed.
     */
    private function backfill(): void
    {
        DB::table('sedes')
            ->where('nombre', 'Sede Central')
            ->whereNull('codigo_localidad')
            ->update(['codigo_localidad' => '1']);
    }
};
