<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-type donation duration in minutes. Nullable keeps legacy catalog rows
 * valid: a missing duration counts as `duracion_minima` when capacity is
 * evaluated (spec A3). No backfill is performed here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tipos_donacion', function (Blueprint $table) {
            $table->integer('duracion_minutos')->nullable()->after('es_aferesis');
        });
    }

    public function down(): void
    {
        Schema::table('tipos_donacion', function (Blueprint $table) {
            $table->dropColumn('duracion_minutos');
        });
    }
};
