<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Donor sex and height, both nullable and additive so legacy patients keep
 * reading NULL. `sexo` is a constrained single char ('M'/'F' checked at the
 * application layer) and `altura` is height in centimetres. Together with
 * `donaciones.peso_donante` they feed the Nadler bolemia estimate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pacientes', function (Blueprint $table) {
            $table->string('sexo', 1)->nullable()->after('telefono');
            $table->decimal('altura', 5, 1)->nullable()->after('sexo');
        });
    }

    public function down(): void
    {
        Schema::table('pacientes', function (Blueprint $table) {
            $table->dropColumn(['sexo', 'altura']);
        });
    }
};
