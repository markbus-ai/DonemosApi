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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regression guards for abuse cases found while hardening for the demo:
 * malformed input must be a 422/409, never a 500, and business rules must not
 * be bypassable through dates, states or formatting.
 */
class DemoHardeningTest extends TestCase
{
    use RefreshDatabase;

    private Sede $sede;

    private TipoDonacion $sangre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-22 10:00:00');

        $this->sede = Sede::firstOrCreate(['nombre' => 'Sede Central'], ['activa' => true]);
        Aptitud::firstOrCreate(['tipo' => 'APTO']);
        $this->sangre = TipoDonacion::firstOrCreate(['codigo' => 'SANGRE'], ['nombre' => 'SANGRE']);

        $staff = Usuario::create([
            'username' => 'staff_'.uniqid(),
            'password_hash' => Hash::make('1234'),
            'rol_id' => Rol::firstOrCreate(['nombre' => 'staff'])->id,
            'sede_id' => $this->sede->id,
        ]);

        Sanctum::actingAs($staff, ['*'], 'staff');
    }

    private function paciente(array $overrides = []): Paciente
    {
        return Paciente::create(array_merge([
            'dni' => (string) fake()->unique()->numerify('########'),
            'nombre' => 'Ana',
            'apellido' => 'Gomez',
            'telefono' => '2254000000',
            'sexo' => 'F',
            'aptitud_id' => Aptitud::firstOrCreate(['tipo' => 'APTO'])->id,
        ], $overrides));
    }

    private function donar(Paciente $paciente, array $overrides = [])
    {
        return $this->postJson('/api/donaciones', array_merge([
            'paciente_id' => $paciente->id,
            'tipo_id' => $this->sangre->id,
            'componentes' => [$this->sangre->id],
            'fecha' => '2026-10-22',
        ], $overrides));
    }

    public function test_login_with_array_username_is_422_not_500(): void
    {
        $this->postJson('/api/auth/login', ['username' => ['a'], 'password' => 'x'])
            ->assertStatus(422);
    }

    public function test_list_filters_reject_arrays_and_bad_dates(): void
    {
        $this->getJson('/api/pacientes?search[]=a')->assertStatus(422);
        $this->getJson('/api/donaciones?search[]=a')->assertStatus(422);
        $this->getJson('/api/donaciones?desde=zzz')->assertStatus(422);
        $this->getJson('/api/donaciones?desde=2026-12-31&hasta=2026-01-01')->assertStatus(422);
        $this->getJson('/api/turnos?fecha=zzz')->assertStatus(422);
    }

    public function test_taken_slot_is_409_horario_ocupado_not_500(): void
    {
        $body = ['fecha' => '2026-12-01', 'hora' => '10:00'];

        $this->postJson('/api/turnos', [...$body, 'paciente_id' => $this->paciente()->id])->assertCreated();

        $this->postJson('/api/turnos', [...$body, 'paciente_id' => $this->paciente()->id])
            ->assertStatus(409)
            ->assertJsonPath('code', 'HORARIO_OCUPADO');

        $this->postJson('/api/turnos', [...$body, 'paciente_id' => $this->paciente()->id, 'forzar' => true, 'motivo' => 'x'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'HORARIO_OCUPADO');
    }

    public function test_cancelled_turnos_do_not_consume_capacity(): void
    {
        for ($i = 0; $i < 32; $i++) {
            Turno::create([
                'paciente_id' => $this->paciente()->id,
                'sede_id' => $this->sede->id,
                'fecha' => '2026-12-02',
                'hora' => sprintf('%02d:%02d', 8 + intdiv($i * 15, 60), ($i * 15) % 60),
                'estado' => 'CANCELADO',
            ]);
        }

        $this->postJson('/api/turnos', ['paciente_id' => $this->paciente()->id, 'fecha' => '2026-12-02', 'hora' => '17:00'])
            ->assertCreated();
    }

    public function test_turnos_cannot_be_booked_in_the_past_or_far_future(): void
    {
        $paciente = $this->paciente();

        $this->postJson('/api/turnos', ['paciente_id' => $paciente->id, 'fecha' => '2026-10-21', 'hora' => '10:00'])
            ->assertStatus(422)->assertJsonValidationErrors('fecha');
        $this->postJson('/api/turnos', ['paciente_id' => $paciente->id, 'fecha' => '9999-12-31', 'hora' => '10:00'])
            ->assertStatus(422)->assertJsonValidationErrors('fecha');
    }

    public function test_no_apto_donor_needs_forzar(): void
    {
        $paciente = $this->paciente(['aptitud_id' => Aptitud::firstOrCreate(['tipo' => 'NO_APTO'])->id]);

        $this->donar($paciente)
            ->assertStatus(409)
            ->assertJsonFragment(['code' => 'BLOQUEO_NO_APTO']);
    }

    public function test_interval_applies_to_back_dated_donations(): void
    {
        $paciente = $this->paciente(['sexo' => 'M']);

        $this->donar($paciente, ['fecha' => '2026-10-20'])->assertCreated();

        $this->donar($paciente, ['fecha' => '2026-10-19'])
            ->assertStatus(409)
            ->assertJsonFragment(['code' => 'WARNING_INTERVALO_SEXO']);
    }

    public function test_unknown_sex_uses_the_strictest_interval(): void
    {
        $paciente = $this->paciente(['sexo' => null]);

        $this->donar($paciente)->assertCreated();

        $this->donar($paciente)
            ->assertStatus(409)
            ->assertJsonFragment(['code' => 'WARNING_INTERVALO_SEXO']);
    }

    public function test_donation_date_cannot_be_in_the_future_or_absurd(): void
    {
        $paciente = $this->paciente();

        $this->donar($paciente, ['fecha' => '2099-01-01'])->assertStatus(422)->assertJsonValidationErrors('fecha');
        $this->donar($paciente, ['fecha' => '0001-01-01'])->assertStatus(422)->assertJsonValidationErrors('fecha');
    }

    public function test_dni_must_be_7_or_8_digits(): void
    {
        foreach (['30.111.222', 'abc💩', '123', str_repeat('1', 50)] as $dni) {
            $this->postJson('/api/pacientes', ['dni' => $dni, 'nombre' => 'A', 'apellido' => 'B', 'telefono' => '2254000000'])
                ->assertStatus(422)
                ->assertJsonValidationErrors('dni');
        }

        $this->postJson('/api/pacientes', ['dni' => '30111222', 'nombre' => 'A', 'apellido' => 'B', 'telefono' => '2254000000'])
            ->assertCreated();

        app()->setLocale('es');
        $this->postJson('/api/pacientes', ['dni' => '12.345.678', 'nombre' => 'A', 'apellido' => 'B', 'telefono' => '2254000000'])
            ->assertJsonPath('errors.dni.0', 'El DNI debe tener 7 u 8 dígitos, sin puntos.');
    }

    public function test_dni_with_leading_zero_duplicates_existing_patient(): void
    {
        $this->paciente(['dni' => '30111222']);

        $this->postJson('/api/pacientes', ['dni' => '030111222', 'nombre' => 'A', 'apellido' => 'B', 'telefono' => '2254000000'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('dni');
    }

    public function test_show_includes_aptitud_and_keeps_raw_fields(): void
    {
        $paciente = $this->paciente();

        $this->getJson('/api/pacientes/'.$paciente->dni)
            ->assertOk()
            ->assertJsonPath('aptitud_id', $paciente->aptitud_id)
            ->assertJsonPath('aptitud.tipo', 'APTO');
    }

    public function test_turno_state_transitions_are_one_way(): void
    {
        $turno = Turno::create([
            'paciente_id' => $this->paciente()->id,
            'sede_id' => $this->sede->id,
            'fecha' => '2026-12-03',
            'hora' => '10:00',
            'estado' => 'PENDIENTE',
        ]);

        $this->patchJson("/api/turnos/{$turno->id}", ['estado' => 'CANCELADO'])->assertOk();
        $this->patchJson("/api/turnos/{$turno->id}", ['estado' => 'ATENDIDO'])->assertStatus(422);
        $this->patchJson("/api/turnos/{$turno->id}", ['estado' => 'PENDIENTE'])->assertStatus(422);
    }

    public function test_reminder_only_for_future_pending_turnos(): void
    {
        $cancelado = Turno::create([
            'paciente_id' => $this->paciente()->id,
            'sede_id' => $this->sede->id,
            'fecha' => '2026-12-04',
            'hora' => '10:00',
            'estado' => 'CANCELADO',
        ]);
        $pasado = Turno::create([
            'paciente_id' => $this->paciente()->id,
            'sede_id' => $this->sede->id,
            'fecha' => '2026-01-04',
            'hora' => '10:00',
            'estado' => 'PENDIENTE',
        ]);

        $this->postJson("/api/turnos/{$cancelado->id}/recordatorio")->assertStatus(422);
        $this->postJson("/api/turnos/{$pasado->id}/recordatorio")->assertStatus(422);
    }

    public function test_partial_cycle_override_is_rejected(): void
    {
        $this->donar($this->paciente(), ['ciclo' => 5])->assertStatus(422);
    }

    public function test_cycle_override_respects_series_and_donation_limits(): void
    {
        $this->donar($this->paciente(), ['ciclo' => 1, 'serie' => 4, 'donacion' => 1])
            ->assertStatus(422)->assertJsonValidationErrors('serie');
        $this->donar($this->paciente(), ['ciclo' => 1, 'serie' => 1, 'donacion' => 6])
            ->assertStatus(422)->assertJsonValidationErrors('donacion');
    }

    public function test_impossible_clinical_values_are_rejected(): void
    {
        $paciente = $this->paciente();

        foreach ([
            'hemoglobina' => 99999999,
            'hematocrito' => 500,
            'plaquetas' => 99999999999,
            'presion_sistolica' => 0,
            'presion_diastolica' => 900,
            'frecuencia_cardiaca' => 0,
            'peso_donante' => 5000,
        ] as $campo => $valor) {
            $this->donar($paciente, [$campo => $valor])
                ->assertStatus(422)
                ->assertJsonValidationErrors($campo);
        }

        $this->donar($paciente, ['presion_sistolica' => 80, 'presion_diastolica' => 120])
            ->assertStatus(422)
            ->assertJsonValidationErrors('presion_diastolica');

        $this->assertSame(0, Donacion::count());
    }
}
