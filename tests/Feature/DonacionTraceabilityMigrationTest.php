<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Backward safety of the additive traceability migrations: every down() must
 * drop exactly what up() added, without touching unrelated schema.
 */
class DonacionTraceabilityMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(string $file): object
    {
        $path = database_path('migrations/'.$file);

        if (! file_exists($path)) {
            $this->fail('Migration file has not been created yet: '.$file);
        }

        return require $path;
    }

    public function test_secuencias_rollback_drops_counter_table(): void
    {
        $this->migration('2026_10_03_000004_create_secuencias_donacion_table.php')->down();

        $this->assertFalse(Schema::hasTable('secuencias_donacion'));
    }

    public function test_numero_donacion_rollback_drops_column(): void
    {
        $this->migration('2026_10_03_000005_add_numero_donacion_to_donaciones_table.php')->down();

        $this->assertFalse(Schema::hasColumn('donaciones', 'numero_donacion'));
    }

    public function test_bag_fields_rollback_drops_every_added_column(): void
    {
        $this->migration('2026_10_03_000006_add_bag_fields_to_donaciones_table.php')->down();

        foreach ([
            'tipo_bolsa',
            'anticoagulante',
            'lote',
            'tubuladura',
            'brazo',
            'dificultad',
            'operador_id',
            'doble_etiqueta',
        ] as $column) {
            $this->assertFalse(Schema::hasColumn('donaciones', $column), "Column {$column} was not dropped.");
        }
    }
}
