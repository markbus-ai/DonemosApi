<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional donation type on a turno. Nullable so pre-existing turnos stay
 * valid (legacy duration falls back to `duracion_minima`). `restrictOnDelete`
 * prevents deleting a type that is still referenced by an appointment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('turnos', function (Blueprint $table) {
            $table->foreignId('tipo_id')
                ->nullable()
                ->after('paciente_id')
                ->constrained('tipos_donacion')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('turnos', function (Blueprint $table) {
            $table->dropForeign(['tipo_id']);
            $table->dropColumn('tipo_id');
        });
    }
};
