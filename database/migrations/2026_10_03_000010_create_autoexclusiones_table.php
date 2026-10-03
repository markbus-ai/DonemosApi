<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Confidential self-exclusion, keyed by donation and never by patient.
 *
 * UNIQUE(donacion_id) is the integrity backstop against a duplicate record;
 * `created_by` is nullable attribution that must not block the user lifecycle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('autoexclusiones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('donacion_id')->unique()->constrained('donaciones')->restrictOnDelete();
            $table->string('motivo')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('autoexclusiones');
    }
};
