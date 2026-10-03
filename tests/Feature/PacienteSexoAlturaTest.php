<?php

namespace Tests\Feature;

use App\Models\Aptitud;
use App\Models\Paciente;
use App\Support\Bolemia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Additive `pacientes.sexo` / `pacientes.altura` schema and model contract.
 * Legacy patients keep reading NULL; bolemia is only derivable once sex,
 * height and weight are all present.
 */
class PacienteSexoAlturaTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_10_03_000013_add_sexo_altura_to_pacientes_table.php';

    private function migration(): object
    {
        $path = database_path(self::MIGRATION);

        if (! file_exists($path)) {
            $this->fail('Migration file has not been created yet: '.self::MIGRATION);
        }

        return require $path;
    }

    private function paciente(array $overrides = []): Paciente
    {
        $aptitud = Aptitud::firstOrCreate(['tipo' => 'APTO']);

        return Paciente::create(array_merge([
            'dni' => (string) fake()->unique()->numerify('########'),
            'nombre' => fake()->firstName(),
            'apellido' => fake()->lastName(),
            'telefono' => fake()->phoneNumber(),
            'aptitud_id' => $aptitud->id,
        ], $overrides));
    }

    public function test_sexo_and_altura_columns_exist(): void
    {
        $this->assertTrue(Schema::hasColumn('pacientes', 'sexo'));
        $this->assertTrue(Schema::hasColumn('pacientes', 'altura'));
    }

    public function test_rollback_drops_only_added_columns(): void
    {
        $this->migration()->down();

        $this->assertFalse(Schema::hasColumn('pacientes', 'sexo'));
        $this->assertFalse(Schema::hasColumn('pacientes', 'altura'));
        $this->assertTrue(Schema::hasColumn('pacientes', 'dni'));
    }

    public function test_patient_stores_sexo_and_altura(): void
    {
        $paciente = $this->paciente(['sexo' => 'F', 'altura' => 165]);
        $fresh = $paciente->fresh();

        $this->assertSame('F', $fresh->sexo);
        $this->assertEqualsWithDelta(165.0, (float) $fresh->altura, 0.01);
    }

    public function test_legacy_patient_reads_null_sexo_and_altura(): void
    {
        $fresh = $this->paciente()->fresh();

        $this->assertNull($fresh->sexo);
        $this->assertNull($fresh->altura);
    }

    public function test_bolemia_is_null_when_patient_is_missing_data(): void
    {
        $onlySexo = $this->paciente(['sexo' => 'M']);
        $this->assertNull(Bolemia::compute($onlySexo->sexo, $onlySexo->altura, null));

        $onlyAltura = $this->paciente(['altura' => 180]);
        $this->assertNull(Bolemia::compute($onlyAltura->sexo, $onlyAltura->altura, 80.0));
    }

    public function test_bolemia_is_computable_when_sex_altura_and_weight_are_present(): void
    {
        $paciente = $this->paciente(['sexo' => 'M', 'altura' => 180]);

        $ml = Bolemia::compute($paciente->sexo, $paciente->altura, 80.0);

        $this->assertEqualsWithDelta(5319.06, $ml, 0.5);
    }
}
