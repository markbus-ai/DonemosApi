<?php

namespace Tests\Feature;

use App\Models\Aptitud;
use App\Models\Paciente;
use App\Models\Rol;
use App\Models\TipoDonacion;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Auto-computed donation-cycle position (`ciclo` / `serie` / `donacion`).
 *
 * The system advances the donor's odometer on every donation: donacion 1..5
 * within a serie, serie 1..3 within a ciclo, then a new ciclo. The limits come
 * from config('ciclo_donacion.*'). An explicit override (all three fields sent)
 * is respected as-is and re-syncs the patient counters.
 */
class DonacionCicloAutoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $rol = Rol::firstOrCreate(['nombre' => 'ADMIN']);
        $staff = Usuario::create([
            'username' => 'user_'.uniqid(),
            'password_hash' => Hash::make('1234'),
            'rol_id' => $rol->id,
        ]);

        Sanctum::actingAs($staff, ['*'], 'staff');
    }

    private function tipo(string $codigo = 'SANGRE'): TipoDonacion
    {
        return TipoDonacion::firstOrCreate(['codigo' => $codigo], ['nombre' => $codigo]);
    }

    private function paciente(array $overrides = []): Paciente
    {
        $aptitud = Aptitud::firstOrCreate(['tipo' => 'APTO']);

        return Paciente::create(array_merge([
            'dni' => (string) fake()->unique()->numerify('########'),
            'nombre' => 'Ana',
            'apellido' => 'Gomez',
            'telefono' => fake()->phoneNumber(),
            'aptitud_id' => $aptitud->id,
        ], $overrides));
    }

    private function payload(Paciente $paciente, TipoDonacion $tipo, array $overrides = []): array
    {
        return array_merge([
            'paciente_id' => $paciente->id,
            'tipo_id' => $tipo->id,
            'componentes' => [$tipo->id],
            'fecha' => now()->toDateString(),
        ], $overrides);
    }

    private function donate(Paciente $paciente, TipoDonacion $tipo, array $overrides = []): int
    {
        $response = $this->postJson('/api/donaciones', $this->payload($paciente, $tipo, $overrides));
        $response->assertStatus(201);

        return $response->json('id');
    }

    private function assertDonation(int $donacionId, int $ciclo, int $serie, int $donacion): void
    {
        $this->assertDatabaseHas('donaciones', [
            'id' => $donacionId,
            'ciclo' => $ciclo,
            'serie' => $serie,
            'donacion' => $donacion,
        ]);
    }

    private function assertCounters(Paciente $paciente, ?int $ciclo, ?int $serie, ?int $donacion): void
    {
        $fresh = $paciente->fresh();
        $this->assertSame($ciclo, $fresh->ciclo_actual, 'ciclo_actual');
        $this->assertSame($serie, $fresh->serie_actual, 'serie_actual');
        $this->assertSame($donacion, $fresh->donacion_actual, 'donacion_actual');
    }

    public function test_first_donation_without_counters_starts_at_one_one_one(): void
    {
        $tipo = $this->tipo();
        $paciente = $this->paciente();

        $id = $this->donate($paciente, $tipo);

        $this->assertDonation($id, 1, 1, 1);
        $this->assertCounters($paciente, 1, 1, 1);
    }

    public function test_sequential_donations_advance_and_roll_serie_at_five(): void
    {
        $tipo = $this->tipo();
        $paciente = $this->paciente();

        $expected = [
            [1, 1, 1],
            [1, 1, 2],
            [1, 1, 3],
            [1, 1, 4],
            [1, 1, 5],
            // Serie rolls over at 5 -> next is 1/2/1.
            [1, 2, 1],
        ];

        foreach ($expected as [$ciclo, $serie, $donacion]) {
            $id = $this->donate($paciente, $tipo);
            $this->assertDonation($id, $ciclo, $serie, $donacion);
            $this->assertCounters($paciente, $ciclo, $serie, $donacion);
        }
    }

    public function test_ciclo_rolls_over_after_last_serie(): void
    {
        $tipo = $this->tipo();
        // Odometer at the end of serie 3 of ciclo 1 -> next opens ciclo 2.
        $paciente = $this->paciente([
            'ciclo_actual' => 1,
            'serie_actual' => 3,
            'donacion_actual' => 5,
        ]);

        $id = $this->donate($paciente, $tipo);

        $this->assertDonation($id, 2, 1, 1);
        $this->assertCounters($paciente, 2, 1, 1);
    }

    public function test_explicit_override_is_used_and_syncs_patient_counters(): void
    {
        $tipo = $this->tipo();
        $paciente = $this->paciente();

        $id = $this->donate($paciente, $tipo, [
            'ciclo' => 2,
            'serie' => 3,
            'donacion' => 4,
        ]);

        $this->assertDonation($id, 2, 3, 4);
        $this->assertCounters($paciente, 2, 3, 4);
    }

    public function test_partial_cycle_fields_are_not_an_override(): void
    {
        $tipo = $this->tipo();
        $paciente = $this->paciente();

        // Only one of the three fields -> falls through to the computed branch.
        $id = $this->donate($paciente, $tipo, ['ciclo' => 9]);

        $this->assertDonation($id, 1, 1, 1);
        $this->assertCounters($paciente, 1, 1, 1);
    }

    public function test_config_limits_drive_the_rollover(): void
    {
        config([
            'ciclo_donacion.donaciones_por_serie' => 2,
            'ciclo_donacion.series_por_ciclo' => 2,
        ]);

        $tipo = $this->tipo();
        $paciente = $this->paciente();

        $expected = [
            [1, 1, 1],
            [1, 1, 2],
            // donacion rolls over at 2 -> serie + 1.
            [1, 2, 1],
            [1, 2, 2],
            // serie rolls over at 2 -> new ciclo.
            [2, 1, 1],
        ];

        foreach ($expected as [$ciclo, $serie, $donacion]) {
            $id = $this->donate($paciente, $tipo);
            $this->assertDonation($id, $ciclo, $serie, $donacion);
            $this->assertCounters($paciente, $ciclo, $serie, $donacion);
        }
    }
}
