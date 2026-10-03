<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-unit discard state. `descartado` defaults to false so legacy component
 * rows stay valid; `motivo_descarte` is nullable but set when discarding.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('componentes_donacion', function (Blueprint $table) {
            $table->boolean('descartado')->default(false)->after('peso');
            $table->string('motivo_descarte')->nullable()->after('descartado');
        });
    }

    public function down(): void
    {
        Schema::table('componentes_donacion', function (Blueprint $table) {
            $table->dropColumn(['descartado', 'motivo_descarte']);
        });
    }
};
