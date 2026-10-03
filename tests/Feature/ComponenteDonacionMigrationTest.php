<?php

namespace Tests\Feature;

use App\Models\Aptitud;
use App\Models\ComponenteDonacion;
use App\Models\Donacion;
use App\Models\Paciente;
use App\Models\TipoDonacion;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Forward/backward behaviour of the additive componentes_donacion migration
 * and the models that expose it.
 */
class ComponenteDonacionMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_10_03_000002_create_componentes_donacion_table.php';

    private function migration(): object
    {
        $path = database_path(self::MIGRATION);

        if (! file_exists($path)) {
            $this->fail('Componentes donacion migration file has not been created yet: '.self::MIGRATION);
        }

        return require $path;
    }

    private function paciente(): Paciente
    {
        $aptitud = Aptitud::firstOrCreate(['tipo' => 'APTO']);

        return Paciente::create([
            'dni' => (string) fake()->unique()->numerify('########'),
            'nombre' => fake()->firstName(),
            'apellido' => fake()->lastName(),
            'telefono' => fake()->phoneNumber(),
            'aptitud_id' => $aptitud->id,
        ]);
    }

    private function tipo(string $codigo): TipoDonacion
    {
        return TipoDonacion::firstOrCreate(['codigo' => $codigo], ['nombre' => $codigo]);
    }

    private function donacion(Paciente $paciente, TipoDonacion $tipo): Donacion
    {
        return Donacion::create([
            'paciente_id' => $paciente->id,
            'tipo_id' => $tipo->id,
            'fecha' => now()->toDateString(),
        ]);
    }

    public function test_donation_owns_one_row_per_component(): void
    {
        $paciente = $this->paciente();
        $sangre = $this->tipo('SANGRE');
        $donacion = $this->donacion($paciente, $sangre);

        $donacion->componentes()->createMany([
            ['tipo_id' => $sangre->id],
        ]);

        $this->assertSame(1, $donacion->fresh()->componentes()->count());
        $this->assertDatabaseHas('componentes_donacion', [
            'donacion_id' => $donacion->id,
            'tipo_id' => $sangre->id,
        ]);
    }

    public function test_plasma_and_platelets_persist_four_rows(): void
    {
        $paciente = $this->paciente();
        $plasma = $this->tipo('PLASMA');
        $plaquetas = $this->tipo('PLAQUETAS');
        $donacion = $this->donacion($paciente, $plasma);

        $donacion->componentes()->createMany([
            ['tipo_id' => $plasma->id],
            ['tipo_id' => $plaquetas->id],
            ['tipo_id' => $plaquetas->id],
            ['tipo_id' => $plaquetas->id],
        ]);

        $this->assertSame(4, $donacion->fresh()->componentes()->count());
        $this->assertSame(
            3,
            ComponenteDonacion::where('donacion_id', $donacion->id)->where('tipo_id', $plaquetas->id)->count()
        );
    }

    public function test_componente_relations_resolve_donation_and_type(): void
    {
        $paciente = $this->paciente();
        $plasma = $this->tipo('PLASMA');
        $donacion = $this->donacion($paciente, $plasma);
        $componente = $donacion->componentes()->create(['tipo_id' => $plasma->id]);

        $this->assertTrue($componente->donacion->is($donacion));
        $this->assertTrue($componente->tipoDonacion->is($plasma));
        $this->assertSame($donacion->id, $plasma->componentes()->first()->donacion_id);
    }

    public function test_tipo_delete_is_restricted_by_components(): void
    {
        $paciente = $this->paciente();
        $plasma = $this->tipo('PLASMA');
        $donacion = $this->donacion($paciente, $plasma);
        $donacion->componentes()->create(['tipo_id' => $plasma->id]);

        $this->expectException(QueryException::class);

        $plasma->delete();
    }

    public function test_donation_delete_is_restricted_by_components(): void
    {
        $paciente = $this->paciente();
        $plasma = $this->tipo('PLASMA');
        $donacion = $this->donacion($paciente, $plasma);
        $donacion->componentes()->create(['tipo_id' => $plasma->id]);

        $this->expectException(QueryException::class);

        $donacion->delete();
    }

    public function test_rollback_drops_componentes_table(): void
    {
        $this->migration()->down();

        $this->assertFalse(Schema::hasTable('componentes_donacion'));
    }
}
