<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Explicit apheresis classification on the donation-type catalog. SQLite-safe:
 * a boolean added with a default is the only way to add a NOT NULL column to a
 * populated table. Backfill maps the catalog codes known to the seeder;
 * PLASMA-as-apheresis is an UNVERIFIED product assumption (spec S5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tipos_donacion', function (Blueprint $table) {
            $table->boolean('es_aferesis')->default(false)->after('codigo');
        });

        DB::table('tipos_donacion')
            ->whereIn('codigo', ['PLASMA', 'PLAQUETAS'])
            ->update(['es_aferesis' => true]);
    }

    public function down(): void
    {
        Schema::table('tipos_donacion', function (Blueprint $table) {
            $table->dropColumn('es_aferesis');
        });
    }
};
