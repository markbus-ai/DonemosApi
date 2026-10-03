<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platelet-enable windows per patient. History is append-only: a re-enable
 * closes the prior row rather than deleting it. The patient's active window is
 * `desde <= D < hasta`; there is no DB partial unique for "one active window",
 * so the service closes prior rows transactionally.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('habilitaciones_plaquetas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('paciente_id')->constrained('pacientes')->restrictOnDelete();
            $table->date('desde');
            $table->date('hasta');
            $table->foreignId('created_by')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->timestamps();
            $table->index(['paciente_id', 'desde']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('habilitaciones_plaquetas');
    }
};
