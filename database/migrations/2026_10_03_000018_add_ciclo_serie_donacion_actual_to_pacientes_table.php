<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manual patient cycle counters. `ciclo_actual > serie_actual > donacion_actual`
 * mirror the `ciclo / serie / donacion` metadata stored per donation, but are
 * fully independent: they are set/reset by the staff through the patient PATCH
 * endpoint and are never copied to, incremented by, or inferred from a donation.
 * All three are nullable and additive so legacy patients keep reading NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pacientes', function (Blueprint $table) {
            $table->integer('ciclo_actual')->nullable()->after('altura');
            $table->integer('serie_actual')->nullable()->after('ciclo_actual');
            $table->integer('donacion_actual')->nullable()->after('serie_actual');
        });
    }

    public function down(): void
    {
        Schema::table('pacientes', function (Blueprint $table) {
            $table->dropColumn(['ciclo_actual', 'serie_actual', 'donacion_actual']);
        });
    }
};
