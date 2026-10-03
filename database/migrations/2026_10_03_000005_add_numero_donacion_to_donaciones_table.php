<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Human-readable donation number.
 *
 * Nullable so legacy rows and direct Donacion::create calls stay valid.
 * UNIQUE is the allocation backstop against duplicate numbers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donaciones', function (Blueprint $table) {
            $table->string('numero_donacion')->nullable()->after('fecha');
            $table->unique('numero_donacion');
        });
    }

    public function down(): void
    {
        Schema::table('donaciones', function (Blueprint $table) {
            $table->dropUnique(['numero_donacion']);
        });

        Schema::table('donaciones', function (Blueprint $table) {
            $table->dropColumn('numero_donacion');
        });
    }
};
