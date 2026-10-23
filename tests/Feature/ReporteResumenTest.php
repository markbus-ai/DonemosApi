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
use App\Models\Turno;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * HTTP contract for GET /api/reportes/resumen: a read-only aggregate dashboard
 * for the desktop app. Every metric is a grouped aggregate (no N+1) and the
 * shared desde/hasta filter applies to donaciones.fecha and turnos.fecha.
 */
class ReporteResumenTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsStaff(): void
    {
        // Statistics are role-scoped; an admin-sede of the default sede sees every fixture.
        $rol = Rol::firstOrCreate(['nombre' => Usuario::ROL_ADMIN_SEDE]);

        $staff = Usuario::create([
            'username' => 'user_'.uniqid(),
            'password_hash' => Hash::make('1234'),
            'rol_id' => $rol->id,
            'sede_id' => $this->sede()->id,
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

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/reportes/resumen')->assertStatus(401);
    }

    public function test_returns_totals_and_sex_buckets(): void
    {
        $this->actingAsStaff();
        $this->donacion(['paciente_id' => $this->paciente(['sexo' => 'M'])->id]);
        $this->donacion(['paciente_id' => $this->paciente(['sexo' => 'F'])->id]);
        $this->donacion(['paciente_id' => $this->paciente(['sexo' => null])->id]);

        $response = $this->getJson('/api/reportes/resumen');

        $response->assertStatus(200);
        $response->assertJsonPath('total_donaciones', 3);
        $response->assertJsonPath('por_sexo.M', 1);
        $response->assertJsonPath('por_sexo.F', 1);
        $response->assertJsonPath('por_sexo.sin_dato', 1);
    }

    public function test_groups_product_components_by_tipo_codigo(): void
    {
        $this->actingAsStaff();
        $plasma = $this->tipo('PLASMA');
        $sangre = $this->tipo('SANGRE');

        $donacion = $this->donacion(['tipo_id' => $plasma->id]);
        ComponenteDonacion::create(['donacion_id' => $donacion->id, 'tipo_id' => $plasma->id]);
        ComponenteDonacion::create(['donacion_id' => $donacion->id, 'tipo_id' => $plasma->id]);
        ComponenteDonacion::create(['donacion_id' => $donacion->id, 'tipo_id' => $sangre->id]);

        $response = $this->getJson('/api/reportes/resumen');

        $response->assertStatus(200);
        $this->assertSame(
            [
                ['codigo' => 'PLASMA', 'total' => 2],
                ['codigo' => 'SANGRE', 'total' => 1],
            ],
            $response->json('por_producto'),
        );
    }

    public function test_counts_discarded_donations_from_autoexclusion_and_full_component_discard(): void
    {
        $this->actingAsStaff();
        $tipo = $this->tipo('PLAQUETAS');

        $autoexcluida = $this->donacion();
        Autoexclusion::create(['donacion_id' => $autoexcluida->id, 'motivo' => 'Motivo']);

        $todasDescartadas = $this->donacion();
        ComponenteDonacion::create(['donacion_id' => $todasDescartadas->id, 'tipo_id' => $tipo->id, 'descartado' => true]);
        ComponenteDonacion::create(['donacion_id' => $todasDescartadas->id, 'tipo_id' => $tipo->id, 'descartado' => true]);

        $parcial = $this->donacion();
        ComponenteDonacion::create(['donacion_id' => $parcial->id, 'tipo_id' => $tipo->id, 'descartado' => true]);
        ComponenteDonacion::create(['donacion_id' => $parcial->id, 'tipo_id' => $tipo->id, 'descartado' => false]);

        $sinComponentes = $this->donacion();

        $response = $this->getJson('/api/reportes/resumen');

        $response->assertStatus(200);
        $response->assertJsonPath('total_donaciones', 4);
        $response->assertJsonPath('donaciones_descartadas', 2);
    }

    public function test_counts_turnos_by_estado(): void
    {
        $this->actingAsStaff();
        $paciente = $this->paciente();

        foreach (['PENDIENTE', 'PENDIENTE', 'ATENDIDO'] as $i => $estado) {
            Turno::create([
                'paciente_id' => $paciente->id,
                'sede_id' => $this->sede()->id,
                'tipo_id' => $this->tipo()->id,
                'fecha' => '2026-06-01',
                'hora' => sprintf('%02d:00', 10 + $i),
                'estado' => $estado,
            ]);
        }

        $response = $this->getJson('/api/reportes/resumen');

        $response->assertStatus(200);
        $response->assertJsonPath('turnos_por_estado.PENDIENTE', 2);
        $response->assertJsonPath('turnos_por_estado.ATENDIDO', 1);
    }

    public function test_date_filter_applies_to_donaciones_and_turnos(): void
    {
        $this->actingAsStaff();
        $paciente = $this->paciente(['sexo' => 'F']);

        $this->donacion(['paciente_id' => $paciente->id, 'fecha' => '2026-06-10']);
        $this->donacion(['paciente_id' => $paciente->id, 'fecha' => '2026-07-05']);

        Turno::create([
            'paciente_id' => $paciente->id,
            'sede_id' => $this->sede()->id,
            'tipo_id' => $this->tipo()->id,
            'fecha' => '2026-06-10',
            'hora' => '10:00',
            'estado' => 'ATENDIDO',
        ]);
        Turno::create([
            'paciente_id' => $paciente->id,
            'sede_id' => $this->sede()->id,
            'tipo_id' => $this->tipo()->id,
            'fecha' => '2026-07-05',
            'hora' => '10:00',
            'estado' => 'PENDIENTE',
        ]);

        $response = $this->getJson('/api/reportes/resumen?desde=2026-07-01&hasta=2026-07-31');

        $response->assertStatus(200);
        $response->assertJsonPath('total_donaciones', 1);
        $response->assertJsonPath('por_sexo.F', 1);
        $response->assertJsonPath('turnos_por_estado.PENDIENTE', 1);
        $this->assertNull($response->json('turnos_por_estado.ATENDIDO'));

        // A June-only window sees the other donation and the other turno.
        $june = $this->getJson('/api/reportes/resumen?desde=2026-06-01&hasta=2026-06-30');
        $june->assertStatus(200);
        $june->assertJsonPath('total_donaciones', 1);
        $june->assertJsonPath('turnos_por_estado.ATENDIDO', 1);
        $this->assertNull($june->json('turnos_por_estado.PENDIENTE'));
    }
}
