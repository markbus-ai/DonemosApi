<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add a stable, non-null, UNIQUE product code to the donation-type catalog.
 *
 * SQLite cannot add a NOT NULL column to a populated table without a default,
 * so the load-bearing order is: nullable -> dedupe backfill -> change() NOT NULL
 * -> unique index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tipos_donacion', function (Blueprint $table) {
            $table->string('codigo')->nullable()->after('nombre');
        });

        $this->backfill();

        Schema::table('tipos_donacion', function (Blueprint $table) {
            $table->string('codigo')->nullable(false)->change();
        });

        Schema::table('tipos_donacion', function (Blueprint $table) {
            $table->unique('codigo');
        });
    }

    public function down(): void
    {
        Schema::table('tipos_donacion', function (Blueprint $table) {
            $table->dropUnique(['codigo']);
        });

        Schema::table('tipos_donacion', function (Blueprint $table) {
            $table->dropColumn('codigo');
        });
    }

    /**
     * Give every existing row a code. Known catalog names map to themselves;
     * unknown names are upper-cased and de-duplicated with a numeric suffix.
     */
    private function backfill(): void
    {
        $rows = DB::table('tipos_donacion')->orderBy('id')->get();
        $used = [];

        foreach ($rows as $row) {
            $base = strtoupper(trim((string) $row->nombre));

            if ($base === '') {
                $base = 'TIPO';
            }

            $candidate = $base;
            $suffix = 2;

            while (in_array($candidate, $used, true)) {
                $candidate = $base.'_'.$suffix;
                $suffix++;
            }

            $used[] = $candidate;

            DB::table('tipos_donacion')->where('id', $row->id)->update(['codigo' => $candidate]);
        }
    }
};
