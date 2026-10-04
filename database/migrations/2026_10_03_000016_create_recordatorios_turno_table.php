<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pre-donation reminder records. The rendered text is snapshotted into
 * `contenido` at scheduling time so later config changes never rewrite history.
 * `estado` defaults to PENDIENTE; a manual trigger appends a row (no unique).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recordatorios_turno', function (Blueprint $table) {
            $table->id();
            $table->foreignId('turno_id')->constrained('turnos')->restrictOnDelete();
            $table->string('canal');
            $table->string('estado')->default('PENDIENTE');
            $table->text('contenido');
            $table->unsignedInteger('intento')->default(1);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index('estado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recordatorios_turno');
    }
};
