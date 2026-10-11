<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manual donation-cycle metadata for the blood-bank domain.
 *
 * The cycle is `ciclo > serie > donacion`: `donacion` is the ORDINAL WITHIN THE
 * SERIE (a 1-based position), distinct from `numero_donacion`, which is the
 * traceability code. All three are nullable and additive so legacy rows keep
 * reading NULL. No range or default is enforced and nothing is derived from the
 * patient counters or the donation history: the staff decides every value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donaciones', function (Blueprint $table) {
            $table->integer('ciclo')->nullable()->after('numero_donacion');
            $table->integer('serie')->nullable()->after('ciclo');
            $table->integer('donacion')->nullable()->after('serie');
        });
    }

    public function down(): void
    {
        Schema::table('donaciones', function (Blueprint $table) {
            $table->dropColumn(['ciclo', 'serie', 'donacion']);
        });
    }
};
