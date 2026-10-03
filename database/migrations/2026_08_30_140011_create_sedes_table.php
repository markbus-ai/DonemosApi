<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sedes', function (Blueprint $table) {
            $table->id();
            $table->string('nombre')->unique();
            $table->string('direccion')->nullable();
            $table->string('telefono')->nullable();
            $table->boolean('activa')->default(true);
            $table->timestamps();
        });

        // Default site used to backfill rows created before sedes existed.
        if (! DB::table('sedes')->where('nombre', 'Sede Central')->exists()) {
            DB::table('sedes')->insert([
                'nombre' => 'Sede Central',
                'direccion' => null,
                'telefono' => null,
                'activa' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sedes');
    }
};
