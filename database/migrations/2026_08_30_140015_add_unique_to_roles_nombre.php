<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Fail loudly on duplicates instead of deleting data silently.
        $duplicates = DB::table('roles')
            ->select('nombre')
            ->groupBy('nombre')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('nombre');

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException(
                'Cannot add UNIQUE constraint to roles.nombre, duplicates exist: '.$duplicates->implode(', ')
            );
        }

        Schema::table('roles', function (Blueprint $table) {
            $table->unique('nombre');
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropUnique(['nombre']);
        });
    }
};
