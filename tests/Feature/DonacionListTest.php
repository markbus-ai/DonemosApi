<?php

namespace Tests\Feature;

use App\Models\Aptitud;
use App\Models\Autoexclusion;
use App\Models\ComponenteDonacion;
use App\Models\Donacion;
use App\Models\Paciente;
use App\Models\Rol;
use App\Models\Sede;
use App\Models\TipoDonacion;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * HTTP contract for GET /api/donaciones: a paginated, filterable donation list
 * for the desktop app. Embeds paciente/sede/tipo/componentes/autoexclusion so
 * clients never do N+1 lookups, and reuses the model accessors (presion_arterial,
 * descartada) instead of recomputing them.
 */
class DonacionListTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsStaff(): void
    {
        $rol = Rol::firstOrCreate(['nombre' => 'ADMIN']);

        $staff = Usuario::create([
            'username' => 'user_'.uniqid(),
            'password_hash' => Hash::make('1234'),
            'rol_id' => $rol->id,
        ]);

        Sanctum::actingAs($staff, ['*'], 'staff');
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

    private function sede(string $nombre = 'Sede Central'): Sede
    {
        return Sede::firstOrCreate(['nombre' => $nombre], ['activa' => true]);
    }

    private function tipo(string $codigo = 'SANGRE'): TipoDonacion
    {
        return TipoDonacion::firstOrCreate(['codigo' => $codigo], ['nombre' => $codigo]);
    }

    private function donacion(array $overrides = []): Donacion
    {
        return Donacion::create(array_merge([
            'paciente_id' => $this->paciente()->id,
            'sede_id' => $this->sede()->id,
            'tipo_id' => $this->tipo()->id,
            'fecha' => '2026-06-01',
        ], $overrides));
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/donaciones')->assertStatus(401);
    }

    public function test_index_returns_paginated_shape_with_embedded_relations(): void
    {
        $this->actingAsStaff();
        $paciente = $this->paciente(['dni' => '30111222', 'nombre' => 'Ana', 'apellido' => 'Gomez']);
        $sede = $this->sede('Pinamar');
        $tipo = $this->tipo('PLASMA');
        $componenteTipo = $this->tipo('PLAQUETAS');

        $donacion = $this->donacion([
            'paciente_id' => $paciente->id,
            'sede_id' => $sede->id,
            'tipo_id' => $tipo->id,
            'fecha' => '2026-06-01',
            'numero_donacion' => 'D-0001',
            'tipo_bolsa' => 'CPD',
            'anticoagulante' => 'CPDA-1',
            'lote' => 'L-77',
            'tubuladura' => 'T-1',
            'brazo' => 'izquierdo',
            'dificultad' => 'normal',
            'doble_etiqueta' => true,
            'presion_sistolica' => 120,
            'presion_diastolica' => 80,
            'frecuencia_cardiaca' => 72,
            'peso_donante' => 70.5,
        ]);

        ComponenteDonacion::create([
            'donacion_id' => $donacion->id,
            'tipo_id' => $componenteTipo->id,
            'vencimiento' => '2026-12-01',
            'peso' => 250.25,
        ]);

        $response = $this->getJson('/api/donaciones');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data',
            'links' => ['first', 'last', 'prev', 'next'],
            'meta' => ['current_page', 'per_page', 'total', 'last_page'],
        ]);

        $response->assertJsonPath('data.0.id', $donacion->id);
        $response->assertJsonPath('data.0.numero_donacion', 'D-0001');
        $response->assertJsonPath('data.0.fecha', '2026-06-01');
        $response->assertJsonPath('data.0.tipo_bolsa', 'CPD');
        $response->assertJsonPath('data.0.anticoagulante', 'CPDA-1');
        $response->assertJsonPath('data.0.lote', 'L-77');
        $response->assertJsonPath('data.0.tubuladura', 'T-1');
        $response->assertJsonPath('data.0.brazo', 'izquierdo');
        $response->assertJsonPath('data.0.dificultad', 'normal');
        $response->assertJsonPath('data.0.doble_etiqueta', true);
        $response->assertJsonPath('data.0.presion_arterial', '120/80');
        $response->assertJsonPath('data.0.frecuencia_cardiaca', 72);
        $response->assertJsonPath('data.0.peso_donante', '70.50');
        $response->assertJsonPath('data.0.descartada', false);
        $response->assertJsonPath('data.0.paciente.id', $paciente->id);
        $response->assertJsonPath('data.0.paciente.dni', '30111222');
        $response->assertJsonPath('data.0.paciente.nombre', 'Ana');
        $response->assertJsonPath('data.0.paciente.apellido', 'Gomez');
        $response->assertJsonPath('data.0.sede.nombre', 'Pinamar');
        $response->assertJsonPath('data.0.tipo.codigo', 'PLASMA');
        $response->assertJsonPath('data.0.componentes.0.tipo.codigo', 'PLAQUETAS');
        $response->assertJsonPath('data.0.componentes.0.vencimiento', '2026-12-01');
        $response->assertJsonPath('data.0.componentes.0.peso', '250.25');
        $response->assertJsonPath('data.0.componentes.0.descartado', false);
        $response->assertJsonPath('data.0.componentes.0.motivo_descarte', null);
        $response->assertJsonPath('data.0.autoexclusion', null);
        $response->assertJsonPath('meta.total', 1);
    }

    public function test_index_filters_by_paciente_id(): void
    {
        $this->actingAsStaff();
        $match = $this->paciente();
        $other = $this->paciente();

        $this->donacion(['paciente_id' => $match->id]);
        $this->donacion(['paciente_id' => $other->id]);

        $response = $this->getJson('/api/donaciones?paciente_id='.$match->id);

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $response->assertJsonPath('data.0.paciente.id', $match->id);
    }

    public function test_index_filters_by_desde_and_hasta(): void
    {
        $this->actingAsStaff();
        $this->donacion(['fecha' => '2026-05-31']);
        $this->donacion(['fecha' => '2026-06-01']);
        $this->donacion(['fecha' => '2026-06-15']);
        $this->donacion(['fecha' => '2026-07-01']);

        $desde = $this->getJson('/api/donaciones?desde=2026-06-01');
        $desde->assertStatus(200);
        $this->assertCount(3, $desde->json('data'));

        $hasta = $this->getJson('/api/donaciones?hasta=2026-06-15');
        $hasta->assertStatus(200);
        $this->assertCount(3, $hasta->json('data'));

        $rango = $this->getJson('/api/donaciones?desde=2026-06-01&hasta=2026-06-15');
        $rango->assertStatus(200);
        $this->assertCount(2, $rango->json('data'));
    }

    public function test_index_filters_by_numero_donacion_search(): void
    {
        $this->actingAsStaff();
        $match = $this->donacion(['numero_donacion' => 'D-000123', 'fecha' => '2026-06-01']);
        $this->donacion(['numero_donacion' => 'D-000999', 'fecha' => '2026-06-02']);

        $response = $this->getJson('/api/donaciones?search=000123');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $response->assertJsonPath('data.0.id', $match->id);
    }

    public function test_index_orders_by_fecha_desc_then_id_desc(): void
    {
        $this->actingAsStaff();
        $older = $this->donacion(['fecha' => '2026-06-01']);
        $sameDateFirst = $this->donacion(['fecha' => '2026-06-02']);
        $sameDateSecond = $this->donacion(['fecha' => '2026-06-02']);

        $response = $this->getJson('/api/donaciones');

        $response->assertStatus(200);
        $ids = array_column($response->json('data'), 'id');
        $this->assertSame([$sameDateSecond->id, $sameDateFirst->id, $older->id], $ids);
    }

    public function test_index_defaults_to_fifteen_per_page_and_caps_at_fifty(): void
    {
        $this->actingAsStaff();

        for ($i = 0; $i < 60; $i++) {
            $this->donacion(['fecha' => '2026-06-01']);
        }

        $default = $this->getJson('/api/donaciones');
        $default->assertStatus(200);
        $default->assertJsonPath('meta.per_page', 15);
        $this->assertCount(15, $default->json('data'));

        $capped = $this->getJson('/api/donaciones?per_page=100');
        $capped->assertStatus(200);
        $capped->assertJsonPath('meta.per_page', 50);
        $this->assertCount(50, $capped->json('data'));

        $custom = $this->getJson('/api/donaciones?per_page=2');
        $custom->assertStatus(200);
        $custom->assertJsonPath('meta.per_page', 2);
        $this->assertCount(2, $custom->json('data'));
    }

    public function test_index_marks_descartada_true_when_autoexclusion_exists(): void
    {
        $this->actingAsStaff();
        $donacion = $this->donacion();

        Autoexclusion::create([
            'donacion_id' => $donacion->id,
            'motivo' => 'Riesgo de infección',
        ]);

        $response = $this->getJson('/api/donaciones');

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.descartada', true);
        $response->assertJsonPath('data.0.autoexclusion.motivo', 'Riesgo de infección');
    }

    public function test_index_marks_descartada_true_when_every_component_is_discarded(): void
    {
        $this->actingAsStaff();
        $donacion = $this->donacion();
        $tipo = $this->tipo('PLAQUETAS');

        ComponenteDonacion::create(['donacion_id' => $donacion->id, 'tipo_id' => $tipo->id, 'descartado' => true]);
        ComponenteDonacion::create(['donacion_id' => $donacion->id, 'tipo_id' => $tipo->id, 'descartado' => true]);

        $response = $this->getJson('/api/donaciones');

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.descartada', true);
    }

    public function test_index_marks_descartada_false_when_partial_discard_or_no_components(): void
    {
        $this->actingAsStaff();
        $partial = $this->donacion();
        $tipo = $this->tipo('PLAQUETAS');
        ComponenteDonacion::create(['donacion_id' => $partial->id, 'tipo_id' => $tipo->id, 'descartado' => true]);
        ComponenteDonacion::create(['donacion_id' => $partial->id, 'tipo_id' => $tipo->id, 'descartado' => false]);

        $empty = $this->donacion();

        $response = $this->getJson('/api/donaciones');

        $response->assertStatus(200);
        $byId = collect($response->json('data'))->keyBy('id');
        $this->assertFalse($byId[$partial->id]['descartada']);
        $this->assertFalse($byId[$empty->id]['descartada']);
    }
}
