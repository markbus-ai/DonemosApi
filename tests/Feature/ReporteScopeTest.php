<?php

namespace Tests\Feature;

use App\Models\Aptitud;
use App\Models\Donacion;
use App\Models\Paciente;
use App\Models\Rol;
use App\Models\Sede;
use App\Models\TipoDonacion;
use App\Models\Turno;
use App\Models\Usuario;
use App\Models\Zona;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Role-scoped statistics: admin-sede sees its own sede, admin-zona sees every
 * sede of its zona and may pick one, every other role is forbidden.
 */
class ReporteScopeTest extends TestCase
{
    use RefreshDatabase;

    private Zona $costa;

    private Sede $pinamar;

    private Sede $madariaga;

    private Sede $central;

    protected function setUp(): void
    {
        parent::setUp();

        $this->costa = Zona::create(['nombre' => 'Costa']);
        $interior = Zona::create(['nombre' => 'Interior']);

        $this->pinamar = Sede::create(['nombre' => 'Pinamar', 'activa' => true, 'zona_id' => $this->costa->id]);
        $this->madariaga = Sede::create(['nombre' => 'Madariaga', 'activa' => true, 'zona_id' => $this->costa->id]);
        $this->central = Sede::firstOrCreate(['nombre' => 'Sede Central'], ['activa' => true]);
        $this->central->update(['zona_id' => $interior->id]);

        $this->donaciones($this->pinamar, 2);
        $this->donaciones($this->madariaga, 3);
        $this->donaciones($this->central, 5);
    }

    private function usuario(string $rol, ?int $sedeId = null, ?int $zonaId = null): Usuario
    {
        return Usuario::create([
            'username' => 'user_'.uniqid(),
            'password_hash' => Hash::make('1234'),
            'rol_id' => Rol::firstOrCreate(['nombre' => $rol])->id,
            'sede_id' => $sedeId,
            'zona_id' => $zonaId,
        ]);
    }

    private function actingAs_(Usuario $usuario): void
    {
        Sanctum::actingAs($usuario, ['*'], 'staff');
    }

    private function donaciones(Sede $sede, int $cantidad): void
    {
        $aptitud = Aptitud::firstOrCreate(['tipo' => 'APTO']);
        $tipo = TipoDonacion::firstOrCreate(['codigo' => 'SANGRE'], ['nombre' => 'SANGRE']);

        for ($i = 0; $i < $cantidad; $i++) {
            $paciente = Paciente::create([
                'dni' => (string) fake()->unique()->numerify('########'),
                'nombre' => 'Ana',
                'apellido' => 'Gomez',
                'telefono' => '123',
                'aptitud_id' => $aptitud->id,
            ]);

            Donacion::create([
                'paciente_id' => $paciente->id,
                'sede_id' => $sede->id,
                'tipo_id' => $tipo->id,
                'fecha' => '2026-06-01',
            ]);

            Turno::create([
                'paciente_id' => $paciente->id,
                'sede_id' => $sede->id,
                'fecha' => '2026-06-0'.($i + 1),
                'hora' => '09:00',
                'estado' => 'PENDIENTE',
            ]);
        }
    }

    public function test_admin_sede_sees_only_its_own_sede(): void
    {
        $this->actingAs_($this->usuario('admin-sede', $this->pinamar->id));

        $this->getJson('/api/reportes/resumen')
            ->assertOk()
            ->assertJsonPath('total_donaciones', 2)
            ->assertJsonPath('turnos_por_estado.PENDIENTE', 2);
    }

    public function test_admin_sede_cannot_request_another_sede(): void
    {
        $this->actingAs_($this->usuario('admin-sede', $this->pinamar->id));

        $this->getJson('/api/reportes/resumen?sede_id='.$this->madariaga->id)
            ->assertStatus(403)
            ->assertJsonPath('message', 'No tenés acceso a esa sede.');
    }

    public function test_admin_zona_sees_every_sede_of_its_zona(): void
    {
        $this->actingAs_($this->usuario('admin-zona', null, $this->costa->id));

        $this->getJson('/api/reportes/resumen')
            ->assertOk()
            ->assertJsonPath('total_donaciones', 5)
            ->assertJsonPath('turnos_por_estado.PENDIENTE', 5);
    }

    public function test_admin_zona_can_pick_one_sede_of_its_zona(): void
    {
        $this->actingAs_($this->usuario('admin-zona', null, $this->costa->id));

        $this->getJson('/api/reportes/resumen?sede_id='.$this->madariaga->id)
            ->assertOk()
            ->assertJsonPath('total_donaciones', 3);
    }

    public function test_admin_zona_cannot_pick_a_sede_outside_its_zona(): void
    {
        $this->actingAs_($this->usuario('admin-zona', null, $this->costa->id));

        $this->getJson('/api/reportes/resumen?sede_id='.$this->central->id)
            ->assertStatus(403);
    }

    public function test_malformed_sede_id_is_rejected(): void
    {
        $this->actingAs_($this->usuario('admin-zona', null, $this->costa->id));

        $this->getJson('/api/reportes/resumen?sede_id='.$this->pinamar->id.'abc')
            ->assertStatus(422)
            ->assertJsonValidationErrors('sede_id');
    }

    public function test_admins_without_sede_or_zona_see_nothing(): void
    {
        foreach ([$this->usuario('admin-sede'), $this->usuario('admin-zona')] as $usuario) {
            $this->actingAs_($usuario);

            $this->getJson('/api/reportes/resumen')->assertOk()->assertJsonPath('total_donaciones', 0);
            $this->getJson('/api/reportes/sedes')->assertOk()->assertExactJson([]);
        }
    }

    public function test_operational_staff_is_forbidden(): void
    {
        $this->actingAs_($this->usuario('staff', $this->central->id));

        $this->getJson('/api/reportes/resumen')
            ->assertStatus(403)
            ->assertJsonPath('message', 'Tu rol no permite ver estadísticas.');
        $this->getJson('/api/reportes/sedes')->assertStatus(403);
    }

    public function test_sedes_lists_visible_sedes_for_the_picker(): void
    {
        $this->actingAs_($this->usuario('admin-zona', null, $this->costa->id));

        $this->getJson('/api/reportes/sedes')
            ->assertOk()
            ->assertExactJson([
                ['id' => $this->madariaga->id, 'nombre' => 'Madariaga', 'zona' => 'Costa'],
                ['id' => $this->pinamar->id, 'nombre' => 'Pinamar', 'zona' => 'Costa'],
            ]);
    }

    public function test_me_exposes_role_zone_and_report_access(): void
    {
        $this->actingAs_($this->usuario('admin-zona', null, $this->costa->id));

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('rol', 'admin-zona')
            ->assertJsonPath('zona_id', $this->costa->id)
            ->assertJsonPath('puede_ver_reportes', true);
    }

    public function test_me_reports_no_access_for_operational_staff(): void
    {
        $this->actingAs_($this->usuario('staff', $this->central->id));

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('rol', 'staff')
            ->assertJsonPath('puede_ver_reportes', false);
    }
}
