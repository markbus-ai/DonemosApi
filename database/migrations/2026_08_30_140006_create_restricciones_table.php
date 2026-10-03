<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restricciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('paciente_id')->unique()->constrained('pacientes')->restrictOnDelete();
            $table->foreignId('motivo_id')->constrained('motivos')->restrictOnDelete();
            $table->date('desde');
            $table->date('hasta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restricciones');
    }
};
