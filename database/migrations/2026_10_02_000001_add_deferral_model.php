<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Allow many deferral rows per patient while keeping full history.
        Schema::table('restricciones', function (Blueprint $table) {
            $table->dropUnique(['paciente_id']);
        });

        Schema::table('restricciones', function (Blueprint $table) {
            $table->boolean('permanente')->default(false)->after('hasta');
        });

        // Legacy open-ended rows (NULL hasta) become explicit permanent deferrals.
        DB::table('restricciones')->whereNull('hasta')->update(['permanente' => true]);

        Schema::table('restricciones', function (Blueprint $table) {
            $table->index(['paciente_id', 'desde']);
        });

        // Motivo code and duration are reference data only in this slice.
        Schema::table('motivos', function (Blueprint $table) {
            $table->string('codigo')->nullable()->unique();
            $table->unsignedSmallInteger('plazo_meses')->nullable();
        });
    }

    public function down(): void
    {
        // Fail loudly instead of silently dropping history.
        $duplicates = DB::table('restricciones')
            ->select('paciente_id')
            ->groupBy('paciente_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('paciente_id');

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException(
                'Cannot restore UNIQUE(paciente_id) on restricciones, patients with multiple rows exist: '.$duplicates->implode(', ')
            );
        }

        Schema::table('motivos', function (Blueprint $table) {
            $table->dropUnique(['codigo']);
            $table->dropColumn(['codigo', 'plazo_meses']);
        });

        Schema::table('restricciones', function (Blueprint $table) {
            $table->dropIndex(['paciente_id', 'desde']);
            $table->dropColumn('permanente');
        });

        Schema::table('restricciones', function (Blueprint $table) {
            $table->unique('paciente_id');
        });
    }
};
