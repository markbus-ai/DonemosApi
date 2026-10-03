<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Collection-level bag data plus double-label confirmation and operator
 * attribution. All nullable so legacy rows and direct creates stay valid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donaciones', function (Blueprint $table) {
            $table->string('tipo_bolsa')->nullable()->after('numero_donacion');
            $table->string('anticoagulante')->nullable();
            $table->string('lote')->nullable();
            $table->string('tubuladura')->nullable();
            $table->string('brazo')->nullable();
            $table->string('dificultad')->nullable();
            $table->foreignId('operador_id')->nullable()->constrained('usuarios')->restrictOnDelete();
            $table->boolean('doble_etiqueta')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('donaciones', function (Blueprint $table) {
            $table->dropForeign(['operador_id']);
        });

        Schema::table('donaciones', function (Blueprint $table) {
            $table->dropColumn([
                'tipo_bolsa',
                'anticoagulante',
                'lote',
                'tubuladura',
                'brazo',
                'dificultad',
                'operador_id',
                'doble_etiqueta',
            ]);
        });
    }
};
