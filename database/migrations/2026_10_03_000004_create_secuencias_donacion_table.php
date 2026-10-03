<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-(sede, year) donation counter.
 *
 * UNIQUE(sede_id, anio) is the concurrency backstop: allocation creates the
 * row once and increments it inside the caller's transaction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('secuencias_donacion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sede_id')->constrained('sedes')->restrictOnDelete();
            $table->unsignedInteger('anio');
            $table->unsignedBigInteger('ultimo_numero')->default(0);
            $table->timestamps();
            $table->unique(['sede_id', 'anio']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('secuencias_donacion');
    }
};
