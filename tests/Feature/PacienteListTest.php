<?php

namespace Tests\Feature;

use App\Models\Aptitud;
use App\Models\Paciente;
use App\Models\Rol;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * HTTP contract for GET /api/pacientes: a paginated, searchable patient list
 * for mobile prefetch. Search matches dni, nombre or apellido (case-insensitive)
 * and never exposes clinical data beyond the aptitud label.
 */
class PacienteListTest extends TestCase
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

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/pacientes')->assertStatus(401);
    }

    public function test_index_returns_paginated_shape_with_safe_fields(): void
    {
        $this->actingAsStaff();
        $paciente = $this->paciente([
            'dni' => '30111222',
            'nombre' => 'Ana',
            'apellido' => 'Gomez',
            'sexo' => 'F',
            'altura' => 165,
        ]);

        $response = $this->getJson('/api/pacientes');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data',
            'links' => ['first', 'last', 'prev', 'next'],
            'meta' => ['current_page', 'per_page', 'total', 'last_page'],
        ]);

        $response->assertJsonPath('data.0.id', $paciente->id);
        $response->assertJsonPath('data.0.dni', '30111222');
        $response->assertJsonPath('data.0.nombre', 'Ana');
        $response->assertJsonPath('data.0.apellido', 'Gomez');
        $response->assertJsonPath('data.0.telefono', $paciente->telefono);
        $response->assertJsonPath('data.0.sexo', 'F');
        $response->assertJsonPath('data.0.aptitud.tipo', 'APTO');
        $response->assertJsonPath('data.0.activeDeferral', false);
        $response->assertJsonPath('meta.total', 1);

        // No clinical data must leak into the list payload.
        $this->assertArrayNotHasKey('restricciones', $response->json('data.0'));
        $this->assertArrayNotHasKey('donaciones', $response->json('data.0'));
        $this->assertArrayNotHasKey('observaciones', $response->json('data.0'));
    }

    public function test_index_search_matches_dni_nombre_and_apellido_case_insensitively(): void
    {
        $this->actingAsStaff();
        $match = $this->paciente(['dni' => '12345678', 'nombre' => 'Ana', 'apellido' => 'Gomez']);
        $this->paciente(['dni' => '87654321', 'nombre' => 'Beto', 'apellido' => 'Perez']);

        foreach (['gomez', 'ANA', '1234'] as $term) {
            $response = $this->getJson('/api/pacientes?search='.urlencode($term));

            $response->assertStatus(200);
            $this->assertCount(1, $response->json('data'), "search={$term} should match one row");
            $response->assertJsonPath('data.0.id', $match->id);
        }
    }

    public function test_index_search_can_match_either_field_across_multiple_rows(): void
    {
        $this->actingAsStaff();
        $byName = $this->paciente(['nombre' => 'Carla', 'apellido' => 'Ramos', 'dni' => '10000001']);
        $byDni = $this->paciente(['nombre' => 'Ramiro', 'apellido' => 'Torres', 'dni' => '20000002']);
        $this->paciente(['nombre' => 'Elena', 'apellido' => 'Vidal', 'dni' => '30000003']);

        $response = $this->getJson('/api/pacientes?search=ram');

        $response->assertStatus(200);
        $this->assertEqualsCanonicalizing(
            [$byName->id, $byDni->id],
            array_column($response->json('data'), 'id'),
        );
    }

    public function test_index_respects_per_page(): void
    {
        $this->actingAsStaff();
        $this->paciente();
        $this->paciente();
        $this->paciente();

        $response = $this->getJson('/api/pacientes?per_page=2');

        $response->assertStatus(200);
        $response->assertJsonPath('meta.per_page', 2);
        $response->assertJsonPath('meta.total', 3);
        $this->assertCount(2, $response->json('data'));
    }

    public function test_index_caps_per_page_at_fifty(): void
    {
        $this->actingAsStaff();

        for ($i = 0; $i < 60; $i++) {
            $this->paciente();
        }

        $response = $this->getJson('/api/pacientes?per_page=100');

        $response->assertStatus(200);
        $response->assertJsonPath('meta.per_page', 50);
        $response->assertJsonPath('meta.total', 60);
        $this->assertCount(50, $response->json('data'));
    }

    public function test_index_defaults_to_fifteen_per_page(): void
    {
        $this->actingAsStaff();

        for ($i = 0; $i < 20; $i++) {
            $this->paciente();
        }

        $response = $this->getJson('/api/pacientes');

        $response->assertStatus(200);
        $response->assertJsonPath('meta.per_page', 15);
        $response->assertJsonPath('meta.total', 20);
        $this->assertCount(15, $response->json('data'));
    }

    public function test_index_orders_by_apellido_then_nombre(): void
    {
        $this->actingAsStaff();
        $this->paciente(['apellido' => 'Zapata', 'nombre' => 'Ana']);
        $this->paciente(['apellido' => 'Alvarez', 'nombre' => 'Zoe']);
        $this->paciente(['apellido' => 'Alvarez', 'nombre' => 'Bruno']);
        $this->paciente(['apellido' => 'Benitez', 'nombre' => 'Luis']);

        $response = $this->getJson('/api/pacientes');

        $response->assertStatus(200);
        $this->assertSame(
            ['Alvarez', 'Alvarez', 'Benitez', 'Zapata'],
            array_column($response->json('data'), 'apellido'),
        );
        $this->assertSame(
            ['Bruno', 'Zoe'],
            [array_column($response->json('data'), 'nombre')[0], array_column($response->json('data'), 'nombre')[1]],
        );
    }

    public function test_show_by_dni_still_works_and_is_not_shadowed_by_the_list_route(): void
    {
        $this->actingAsStaff();
        $paciente = $this->paciente(['dni' => '30111222']);

        $response = $this->getJson('/api/pacientes/30111222');

        $response->assertStatus(200);
        $response->assertJsonFragment(['dni' => '30111222']);
        $response->assertJsonPath('id', $paciente->id);
    }
}
