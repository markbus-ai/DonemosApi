<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Turno messages are no longer only reminders: `tipo` is CONFIRMACION,
 * RECORDATORIO or CANCELACION. `destino` is the number actually used (the
 * test number when redirected) and `error` the delivery failure reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recordatorios_turno', function (Blueprint $table) {
            $table->string('tipo')->default('RECORDATORIO');
            $table->string('destino')->nullable();
            $table->text('error')->nullable();
            $table->index(['turno_id', 'tipo']);
        });
    }

    public function down(): void
    {
        Schema::table('recordatorios_turno', function (Blueprint $table) {
            $table->dropIndex(['turno_id', 'tipo']);
            $table->dropColumn(['tipo', 'destino', 'error']);
        });
    }
};
