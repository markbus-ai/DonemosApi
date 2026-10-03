<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Donation components: one row per bag, each referencing a catalog type.
 * Quantity is represented by the row count per type.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('componentes_donacion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('donacion_id')->constrained('donaciones')->restrictOnDelete();
            $table->foreignId('tipo_id')->constrained('tipos_donacion')->restrictOnDelete();
            $table->timestamps();
            $table->index(['donacion_id', 'tipo_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('componentes_donacion');
    }
};
