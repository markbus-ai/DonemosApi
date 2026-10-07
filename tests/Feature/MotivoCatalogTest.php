<?php

namespace Tests\Feature;

use App\Models\Motivo;
use App\Models\Rol;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * HTTP contract for GET /api/motivos: the read-only deferral/observation
 * reason catalog used by the mobile app to build forms. Ordered by nombre.
 */
class MotivoCatalogTest extends TestCase
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

    private function motivo(array $overrides = []): Motivo
    {
        return Motivo::create(array_merge([
            'nombre' => 'Motivo',
            'codigo' => 'CODIGO_'.uniqid(),
        ], $overrides));
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/motivos')->assertStatus(401);
    }

    public function test_index_returns_catalog_fields_ordered_by_nombre(): void
    {
        $this->actingAsStaff();

        $this->motivo(['nombre' => 'Tatuaje', 'codigo' => 'TATUAJE', 'plazo_meses' => 12]);
        $this->motivo(['nombre' => 'Anemia', 'codigo' => 'ANEMIA', 'plazo_meses' => null]);

        $response = $this->getJson('/api/motivos');

        $response->assertStatus(200);
        $response->assertJsonPath('0.nombre', 'Anemia');
        $response->assertJsonPath('0.codigo', 'ANEMIA');
        $response->assertJsonPath('1.nombre', 'Tatuaje');
        $response->assertJsonPath('1.codigo', 'TATUAJE');
    }

    public function test_index_includes_plazo_meses_including_null(): void
    {
        $this->actingAsStaff();

        $this->motivo(['nombre' => 'Corto', 'codigo' => 'CORT', 'plazo_meses' => 6]);
        $this->motivo(['nombre' => 'Largo', 'codigo' => 'LARG', 'plazo_meses' => null]);

        $response = $this->getJson('/api/motivos');

        $response->assertStatus(200);

        $corto = $response->json('0');
        $this->assertSame(6, $corto['plazo_meses']);

        $largo = $response->json('1');
        $this->assertArrayHasKey('plazo_meses', $largo);
        $this->assertNull($largo['plazo_meses']);
    }
}
