<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pre-donation vitals and lab values. All nullable and additive so legacy
 * donations keep reading NULL; `down()` drops exactly what `up()` added.
 * `peso_donante` is the donor body weight, distinct from
 * `componentes_donacion.peso` (per-bag weight).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donaciones', function (Blueprint $table) {
            $table->decimal('hemoglobina', 4, 2)->nullable()->after('doble_etiqueta');
            $table->decimal('hematocrito', 4, 2)->nullable();
            $table->integer('plaquetas')->nullable();
            $table->integer('presion_sistolica')->nullable();
            $table->integer('presion_diastolica')->nullable();
            $table->integer('frecuencia_cardiaca')->nullable();
            $table->decimal('peso_donante', 8, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('donaciones', function (Blueprint $table) {
            $table->dropColumn([
                'hemoglobina',
                'hematocrito',
                'plaquetas',
                'presion_sistolica',
                'presion_diastolica',
                'frecuencia_cardiaca',
                'peso_donante',
            ]);
        });
    }
};
