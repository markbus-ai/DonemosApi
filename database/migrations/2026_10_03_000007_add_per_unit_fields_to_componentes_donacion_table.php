<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-unit expiry and weight on the component row. Nullable so pre-existing
 * rows stay readable; the row is already the traceable unit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('componentes_donacion', function (Blueprint $table) {
            $table->date('vencimiento')->nullable()->after('tipo_id');
            $table->decimal('peso', 8, 2)->nullable()->after('vencimiento');
        });
    }

    public function down(): void
    {
        Schema::table('componentes_donacion', function (Blueprint $table) {
            $table->dropColumn(['vencimiento', 'peso']);
        });
    }
};
