<?php

namespace Tests\Feature;

use App\Models\Aptitud;
use App\Models\Motivo;
use App\Models\Paciente;
use App\Models\Rol;
use App\Models\Sede;
use App\Models\TipoDonacion;
use App\Models\Usuario;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Rules that used to live only in PHP are now also enforced by the database,
 * so a raw write (tinker, import, another client) cannot store nonsense.
 */
class DbGuardsTest extends TestCase
{
    use RefreshDatabase;

    private Paciente $paciente;

    private Motivo $motivo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-22 13:00:00');

        $sede = Sede::firstOrCreate(['nombre' => 'Sede Central'], ['activa' => true]);
        $sede->update(['codigo_localidad' => '1']);
        $this->motivo = Motivo::firstOrCreate(['nombre' => 'Prueba']);
        $this->paciente = Paciente::create([
            'dni' => '30111222',
            'nombre' => 'Ana',
            'apellido' => 'Gomez',
            'telefono' => '2254000000',
            'aptitud_id' => Aptitud::firstOrCreate(['tipo' => 'APTO'])->id,
        ]);

        Sanctum::actingAs(Usuario::create([
            'username' => 'staff_'.uniqid(),
            'password_hash' => Hash::make('1234'),
            'rol_id' => Rol::firstOrCreate(['nombre' => 'staff'])->id,
            'sede_id' => $sede->id,
        ]), ['*'], 'staff');
    }

    private function assertRejected(callable $write): void
    {
        try {
            $write();
        } catch (QueryException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('The database accepted an invalid row.');
    }

    public function test_restriccion_shape_is_enforced_by_the_database(): void
    {
        $base = ['paciente_id' => $this->paciente->id, 'motivo_id' => $this->motivo->id, 'desde' => '2026-10-01', 'created_at' => now(), 'updated_at' => now()];

        $this->assertRejected(fn () => DB::table('restricciones')->insert([...$base, 'permanente' => false, 'hasta' => null]));
        $this->assertRejected(fn () => DB::table('restricciones')->insert([...$base, 'permanente' => true, 'hasta' => '2026-12-01']));
        $this->assertRejected(fn () => DB::table('restricciones')->insert([...$base, 'permanente' => false, 'hasta' => '2026-09-01']));
    }

    public function test_date_windows_cannot_end_before_they_start(): void
    {
        $this->assertRejected(fn () => DB::table('observaciones')->insert(['paciente_id' => $this->paciente->id, 'motivo_id' => $this->motivo->id, 'desde' => '2026-10-10', 'hasta' => '2026-10-01', 'created_at' => now(), 'updated_at' => now()]));
        $this->assertRejected(fn () => DB::table('habilitaciones_plaquetas')->insert(['paciente_id' => $this->paciente->id, 'desde' => '2026-10-10', 'hasta' => '2026-10-01', 'created_at' => now(), 'updated_at' => now()]));
    }

    public function test_enum_like_columns_and_counters_are_enforced(): void
    {
        $this->assertRejected(fn () => DB::table('turnos')->insert(['paciente_id' => $this->paciente->id, 'sede_id' => Sede::value('id'), 'fecha' => '2026-11-01', 'hora' => '09:00', 'estado' => 'LO_QUE_SEA', 'created_at' => now(), 'updated_at' => now()]));
        $this->assertRejected(fn () => DB::table('pacientes')->where('id', $this->paciente->id)->update(['sexo' => 'Z']));
        $this->assertRejected(fn () => DB::table('pacientes')->where('id', $this->paciente->id)->update(['ciclo_actual' => -5]));
        $this->assertRejected(fn () => DB::table('pacientes')->where('id', $this->paciente->id)->update(['dni' => '12.345.678']));
    }

    public function test_locality_codes_are_normalised_to_three_digits(): void
    {
        $this->assertSame('001', Sede::where('nombre', 'Sede Central')->value('codigo_localidad'));

        $this->assertRejected(fn () => Sede::create(['nombre' => 'Duplicada', 'activa' => true, 'codigo_localidad' => '001']));
        $this->assertRejected(fn () => DB::table('sedes')->insert(['nombre' => 'Rara', 'activa' => true, 'codigo_localidad' => '1234', 'created_at' => now(), 'updated_at' => now()]));
    }

    public function test_api_rejects_same_day_and_open_ended_temporary_restrictions(): void
    {
        $url = "/api/pacientes/{$this->paciente->dni}/restriccion";

        $this->postJson($url, ['motivo_id' => $this->motivo->id, 'desde' => '2026-10-22', 'hasta' => '2026-10-22'])
            ->assertStatus(422)->assertJsonValidationErrors('hasta');
        $this->postJson($url, ['motivo_id' => $this->motivo->id, 'desde' => '2026-10-22', 'permanente' => false])
            ->assertStatus(422)->assertJsonValidationErrors('hasta');
    }

    public function test_same_day_observation_is_still_allowed_via_aptitud(): void
    {
        Aptitud::firstOrCreate(['tipo' => 'APTO_OBSERVACION']);

        $this->patchJson("/api/pacientes/{$this->paciente->dni}/aptitud", [
            'tipo' => 'APTO_OBSERVACION', 'motivo_id' => $this->motivo->id, 'desde' => '2026-10-22', 'hasta' => '2026-10-22',
        ])->assertOk();
    }

    public function test_api_bounds_match_column_precision(): void
    {
        $tipo = TipoDonacion::firstOrCreate(['codigo' => 'SANGRE'], ['nombre' => 'SANGRE']);

        $this->postJson('/api/donaciones', ['paciente_id' => $this->paciente->id, 'tipo_id' => $tipo->id, 'componentes' => [$tipo->id], 'hematocrito' => 100])
            ->assertStatus(422)->assertJsonValidationErrors('hematocrito');
        $this->postJson('/api/donaciones', ['paciente_id' => $this->paciente->id, 'tipo_id' => $tipo->id, 'componentes' => [['tipo_id' => $tipo->id, 'peso' => 99999999]]])
            ->assertStatus(422)->assertJsonValidationErrors('componentes.0.peso');
    }
}
