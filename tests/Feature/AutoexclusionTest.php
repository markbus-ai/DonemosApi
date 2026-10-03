<?php

namespace Tests\Feature;

use App\Models\Aptitud;
use App\Models\Donacion;
use App\Models\Paciente;
use App\Models\Rol;
use App\Models\TipoDonacion;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * WU-A behaviour: confidential self-exclusion keyed by donation number discards
 * every unit transactionally, rejects duplicates and exposes a derived
 * donation-level discard accessor.
 */
class AutoexclusionTest extends TestCase
{
    use RefreshDatabase;

    private const FECHA = '2026-05-04';

    private Usuario $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->staff = $this->usuario();
        Sanctum::actingAs($this->staff, ['*'], 'staff');
    }

    private function usuario(): Usuario
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

    private function tipo(string $codigo, bool $esAferesis = false): TipoDonacion
    {
        return TipoDonacion::firstOrCreate(
            ['codigo' => $codigo],
            ['nombre' => $codigo, 'es_aferesis' => $esAferesis],
        );
    }

    private function donacion(array $overrides = []): Donacion
    {
        return Donacion::create(array_merge([
            'paciente_id' => $this->paciente()->id,
            'tipo_id' => $this->tipo('SANGRE')->id,
            'fecha' => self::FECHA,
        ], $overrides));
    }

    public function test_record_by_donation_number_persists_a_confidential_row(): void
    {
        $donacion = $this->donacion(['numero_donacion' => '2026AAA000001']);

        $response = $this->postJson('/api/donaciones/2026AAA000001/autoexclusion', [
            'motivo' => 'Solicitud del donante',
        ]);

        $response->assertStatus(201);
        $response->assertJsonFragment([
            'donacion_id' => $donacion->id,
            'motivo' => 'Solicitud del donante',
            'created_by' => $this->staff->id,
        ]);

        // The response must never re-expose patient identity.
        $response->assertJsonMissingPath('paciente_id');
        $response->assertJsonMissingPath('nombre');
        $response->assertJsonMissingPath('apellido');

        $this->assertDatabaseHas('autoexclusiones', [
            'donacion_id' => $donacion->id,
            'motivo' => 'Solicitud del donante',
            'created_by' => $this->staff->id,
        ]);
    }

    public function test_duplicate_self_exclusion_is_rejected_with_422(): void
    {
        $donacion = $this->donacion(['numero_donacion' => '2026AAA000002']);

        $this->postJson('/api/donaciones/2026AAA000002/autoexclusion', ['motivo' => 'Primera'])
            ->assertStatus(201);

        $this->postJson('/api/donaciones/2026AAA000002/autoexclusion', ['motivo' => 'Segunda'])
            ->assertStatus(422);

        $this->assertSame(1, $donacion->autoexclusion()->count());
        $this->assertDatabaseCount('autoexclusiones', 1);
    }

    public function test_recording_discards_every_component_and_keeps_labs(): void
    {
        $sangre = $this->tipo('SANGRE');
        $plaquetas = $this->tipo('PLAQUETAS');

        $created = $this->postJson('/api/donaciones', [
            'paciente_id' => $this->paciente()->id,
            'tipo_id' => $sangre->id,
            'componentes' => [$plaquetas->id, $plaquetas->id, $plaquetas->id],
            'fecha' => self::FECHA,
            'hemoglobina' => 13.2,
            'peso_donante' => 70,
        ]);

        $created->assertStatus(201);
        $numero = $created->json('numero_donacion');
        $donacion = Donacion::where('numero_donacion', $numero)->firstOrFail();

        $this->assertSame(3, $donacion->componentes()->count());
        $this->assertSame(0, $donacion->componentes()->where('descartado', true)->count());

        $this->postJson('/api/donaciones/'.$numero.'/autoexclusion', ['motivo' => 'Voluntaria'])
            ->assertStatus(201);

        $this->assertSame(3, $donacion->componentes()->where('descartado', true)->count());
        $this->assertSame(0, $donacion->componentes()->whereNull('motivo_descarte')->count());

        // Clinical data captured with the donation is never touched.
        $this->assertSame('13.20', $donacion->fresh()->hemoglobina);
        $this->assertTrue($donacion->fresh()->descartada);
    }

    public function test_missing_motivo_falls_back_to_a_non_null_discard_reason(): void
    {
        $donacion = $this->donacion(['numero_donacion' => '2026AAA000003']);
        $donacion->componentes()->create(['tipo_id' => $this->tipo('SANGRE')->id]);

        $this->postJson('/api/donaciones/2026AAA000003/autoexclusion', [])
            ->assertStatus(201);

        $this->assertNull($donacion->fresh()->autoexclusion->motivo);
        $this->assertSame(
            'Autoexclusión del donante',
            $donacion->componentes()->firstOrFail()->motivo_descarte,
        );
    }

    public function test_zero_component_donation_still_records_self_exclusion(): void
    {
        $donacion = $this->donacion(['numero_donacion' => '2026AAA000004']);

        $this->postJson('/api/donaciones/2026AAA000004/autoexclusion', ['motivo' => 'Sin componentes'])
            ->assertStatus(201);

        $this->assertDatabaseHas('autoexclusiones', ['donacion_id' => $donacion->id]);
        $this->assertTrue($donacion->fresh()->descartada);
    }

    public function test_descartada_accessor_reflects_self_exclusion_and_component_state(): void
    {
        $tipo = $this->tipo('SANGRE');

        // Self-excluded donation.
        $autoexcluida = $this->donacion(['numero_donacion' => '2026AAA000010']);
        $autoexcluida->autoexclusion()->create(['motivo' => 'Voluntaria']);

        // No self-exclusion, one active (non-discarded) component.
        $activa = $this->donacion(['numero_donacion' => '2026AAA000011']);
        $activa->componentes()->create(['tipo_id' => $tipo->id, 'descartado' => false]);

        // No self-exclusion, all components discarded.
        $derivada = $this->donacion(['numero_donacion' => '2026AAA000012']);
        $derivada->componentes()->createMany([
            ['tipo_id' => $tipo->id, 'descartado' => true, 'motivo_descarte' => 'Motivo'],
            ['tipo_id' => $tipo->id, 'descartado' => true, 'motivo_descarte' => 'Motivo'],
        ]);

        // No self-exclusion and no components.
        $vacia = $this->donacion(['numero_donacion' => '2026AAA000013']);

        $this->assertTrue($autoexcluida->fresh()->descartada);
        $this->assertFalse($activa->fresh()->descartada);
        $this->assertTrue($derivada->fresh()->descartada);
        $this->assertFalse($vacia->fresh()->descartada);

        $this->assertArrayHasKey('descartada', $autoexcluida->fresh()->toArray());
        $this->assertTrue($autoexcluida->fresh()->toArray()['descartada']);
    }

    public function test_mixed_components_are_not_derived_as_discarded(): void
    {
        $tipo = $this->tipo('SANGRE');
        $donacion = $this->donacion(['numero_donacion' => '2026AAA000014']);

        $donacion->componentes()->createMany([
            ['tipo_id' => $tipo->id, 'descartado' => true, 'motivo_descarte' => 'Motivo'],
            ['tipo_id' => $tipo->id, 'descartado' => false],
        ]);

        $this->assertFalse($donacion->fresh()->descartada);
    }

    public function test_unknown_donation_number_returns_404(): void
    {
        $this->postJson('/api/donaciones/2026ZZZ999999/autoexclusion', ['motivo' => 'Nada'])
            ->assertStatus(404);
    }
}
