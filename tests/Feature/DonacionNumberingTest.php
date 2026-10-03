<?php

namespace Tests\Feature;

use App\Models\Aptitud;
use App\Models\Donacion;
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
 * End-to-end numbering: format, per-(sede, year) increments, fail-closed and
 * the UNIQUE(numero_donacion) backstop.
 */
class DonacionNumberingTest extends TestCase
{
    use RefreshDatabase;

    private const FECHA = '2026-05-04';

    private Usuario $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->staff = $this->createUsuario();
        Sanctum::actingAs($this->staff, ['*'], 'staff');
    }

    private function createUsuario(): Usuario
    {
        $rol = Rol::firstOrCreate(['nombre' => 'ADMIN']);

        return Usuario::create([
            'username' => 'user_'.uniqid(),
            'password_hash' => Hash::make('1234'),
            'rol_id' => $rol->id,
        ]);
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

    private function tipo(string $codigo = 'PLASMA'): TipoDonacion
    {
        return TipoDonacion::firstOrCreate(['codigo' => $codigo], ['nombre' => $codigo]);
    }

    private function payload(Paciente $paciente, TipoDonacion $tipo, ?int $sedeId = null): array
    {
        return array_filter([
            'paciente_id' => $paciente->id,
            'tipo_id' => $tipo->id,
            'componentes' => [$tipo->id],
            'fecha' => self::FECHA,
            'sede_id' => $sedeId,
        ], fn ($v) => $v !== null);
    }

    public function test_created_donation_gets_formatted_number_from_central_code(): void
    {
        $paciente = $this->paciente();
        $tipo = $this->tipo();

        $response = $this->postJson('/api/donaciones', $this->payload($paciente, $tipo));

        $response->assertStatus(201);
        $this->assertSame('2026001000001', $response->json('numero_donacion'));
        $this->assertDatabaseHas('donaciones', ['numero_donacion' => '2026001000001']);
        $this->assertSame(1, DB::table('secuencias_donacion')->sum('ultimo_numero'));
    }

    public function test_second_donation_increments_the_same_sequence(): void
    {
        $tipo = $this->tipo();

        $first = $this->postJson('/api/donaciones', $this->payload($this->paciente(), $tipo));
        $second = $this->postJson('/api/donaciones', $this->payload($this->paciente(), $tipo));

        $first->assertStatus(201);
        $second->assertStatus(201);
        $this->assertSame('2026001000001', $first->json('numero_donacion'));
        $this->assertSame('2026001000002', $second->json('numero_donacion'));
    }

    public function test_each_sede_numbers_independently(): void
    {
        $tipo = $this->tipo();
        $pinamar = Sede::create(['nombre' => 'Pinamar', 'codigo_localidad' => '003', 'activa' => true]);

        $central = $this->postJson('/api/donaciones', $this->payload($this->paciente(), $tipo));
        $other = $this->postJson('/api/donaciones', $this->payload($this->paciente(), $tipo, $pinamar->id));

        $central->assertStatus(201);
        $other->assertStatus(201);
        $this->assertSame('2026001000001', $central->json('numero_donacion'));
        $this->assertSame('2026003000001', $other->json('numero_donacion'));
    }

    public function test_duplicate_number_is_rejected_by_unique_index(): void
    {
        Donacion::create([
            'paciente_id' => $this->paciente()->id,
            'tipo_id' => $this->tipo()->id,
            'fecha' => self::FECHA,
            'numero_donacion' => '2026001000001',
        ]);

        $this->expectException(QueryException::class);

        Donacion::create([
            'paciente_id' => $this->paciente()->id,
            'tipo_id' => $this->tipo()->id,
            'fecha' => self::FECHA,
            'numero_donacion' => '2026001000001',
        ]);
    }

    public function test_legacy_direct_create_keeps_number_null(): void
    {
        $donacion = Donacion::create([
            'paciente_id' => $this->paciente()->id,
            'tipo_id' => $this->tipo()->id,
            'fecha' => self::FECHA,
        ]);

        $this->assertNull($donacion->fresh()->numero_donacion);
    }

    public function test_fails_closed_when_sede_has_no_locality_code_and_persists_nothing(): void
    {
        $tipo = $this->tipo();
        $sinCodigo = Sede::create(['nombre' => 'Sin codigo', 'codigo_localidad' => null, 'activa' => true]);

        $response = $this->postJson('/api/donaciones', $this->payload($this->paciente(), $tipo, $sinCodigo->id));

        $response->assertStatus(422);
        $this->assertDatabaseCount('donaciones', 0);
        $this->assertDatabaseCount('secuencias_donacion', 0);
    }
}
