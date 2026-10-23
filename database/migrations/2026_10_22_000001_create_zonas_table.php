<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fixed geographic zones grouping sedes. A sede belongs to at most one zona;
 * an admin-zona user is bound to one zona and sees the statistics of its sedes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zonas', function (Blueprint $table) {
            $table->id();
            $table->string('nombre')->unique();
            $table->timestamps();
        });

        Schema::table('sedes', function (Blueprint $table) {
            $table->foreignId('zona_id')->nullable()->constrained('zonas')->nullOnDelete();
        });

        Schema::table('usuarios', function (Blueprint $table) {
            $table->foreignId('zona_id')->nullable()->constrained('zonas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->dropForeign(['zona_id']);
            $table->dropColumn('zona_id');
        });

        Schema::table('sedes', function (Blueprint $table) {
            $table->dropForeign(['zona_id']);
            $table->dropColumn('zona_id');
        });

        Schema::dropIfExists('zonas');
    }
};
