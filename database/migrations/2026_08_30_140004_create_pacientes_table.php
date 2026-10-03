<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pacientes', function (Blueprint $table) {
            $table->id();
            $table->string('dni')->unique();
            $table->string('nombre');
            $table->string('apellido');
            $table->string('telefono');
            // Default APTO se asigna en aplicación (Service/Model).
            // El seeder debe crear aptitudes con tipo='APTO' e id 1.
            // Campo NOT NULL con restrictOnDelete, sin default DB por ser FK.
            $table->foreignId('aptitud_id')->constrained('aptitudes')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pacientes');
    }
};
