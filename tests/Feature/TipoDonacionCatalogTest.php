<?php

namespace Tests\Feature;

use App\Models\Rol;
use App\Models\TipoDonacion;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * HTTP contract for GET /api/tipos-donacion: the read-only donation-type
 * catalog used by the mobile app to build donation forms. Ordered by id.
 */
class TipoDonacionCatalogTest extends TestCase
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

    private function tipo(array $overrides = []): TipoDonacion
    {
        return TipoDonacion::create(array_merge([
            'nombre' => 'Sangre entera',
            'codigo' => 'SANGRE_'.uniqid(),
        ], $overrides));
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/tipos-donacion')->assertStatus(401);
    }

    public function test_index_returns_catalog_fields_ordered_by_id(): void
    {
        $this->actingAsStaff();

        $whole = $this->tipo([
            'nombre' => 'Sangre entera',
            'codigo' => 'SANGRE',
            'es_aferesis' => false,
            'duracion_minutos' => null,
        ]);
        $filtered = $this->tipo([
            'nombre' => 'Plasma',
            'codigo' => 'PLASMA',
            'es_aferesis' => true,
            'duracion_minutos' => 60,
        ]);

        $response = $this->getJson('/api/tipos-donacion');

        $response->assertStatus(200);
        $response->assertJsonPath('0.id', $whole->id);
        $response->assertJsonPath('0.codigo', 'SANGRE');
        $response->assertJsonPath('0.nombre', 'Sangre entera');
        $response->assertJsonPath('1.id', $filtered->id);
        $response->assertJsonPath('1.codigo', 'PLASMA');
    }

    public function test_index_serializes_es_aferesis_as_boolean_and_duracion_minutos(): void
    {
        $this->actingAsStaff();

        $this->tipo([
            'codigo' => 'PLAQUETAS',
            'nombre' => 'Plaquetas',
            'es_aferesis' => true,
            'duracion_minutos' => 90,
        ]);
        $this->tipo([
            'codigo' => 'SANGRE',
            'nombre' => 'Sangre entera',
            'es_aferesis' => false,
            'duracion_minutos' => null,
        ]);

        $response = $this->getJson('/api/tipos-donacion');

        $response->assertStatus(200);

        $filtered = $response->json('0');
        $this->assertIsBool($filtered['es_aferesis']);
        $this->assertTrue($filtered['es_aferesis']);
        $this->assertSame(90, $filtered['duracion_minutos']);

        $whole = $response->json('1');
        $this->assertIsBool($whole['es_aferesis']);
        $this->assertFalse($whole['es_aferesis']);
        $this->assertNull($whole['duracion_minutos']);
    }
}
