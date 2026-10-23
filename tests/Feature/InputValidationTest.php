<?php

namespace Tests\Feature;

use App\Models\Aptitud;
use App\Models\Donacion;
use App\Models\Motivo;
use App\Models\Paciente;
use App\Models\TipoDonacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsAsStaff;
use Tests\TestCase;

/**
 * Malformed input is a 422 (or a Spanish 404), never a 500, and the bounds
 * match what the columns can store.
 */
class InputValidationTest extends TestCase
{
    use ActsAsStaff, RefreshDatabase;

    private TipoDonacion $sangre;

    private Motivo $motivo;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.business_timezone' => 'America/Argentina/Buenos_Aires']);
        $this->travelTo('2026-10-22 13:00:00');
        Aptitud::firstOrCreate(['tipo' => 'APTO']);
        $this->sangre = TipoDonacion::factory()->sangre()->create();
        $this->motivo = Motivo::factory()->create();
        $this->actingAsStaff();
    }

    private function donar(Paciente $paciente, array $overrides = []): TestResponse
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

    public function test_turnos_cannot_be_booked_in_the_past_or_far_future(): void
    {
        $paciente = Paciente::factory()->create();

        $this->postJson('/api/turnos', ['paciente_id' => $paciente->id, 'fecha' => '2026-10-21', 'hora' => '10:00'])
            ->assertStatus(422)->assertJsonValidationErrors('fecha');
        $this->postJson('/api/turnos', ['paciente_id' => $paciente->id, 'fecha' => '9999-12-31', 'hora' => '10:00'])
            ->assertStatus(422)->assertJsonValidationErrors('fecha');
    }

    public function test_donation_date_cannot_be_in_the_future_or_absurd(): void
    {
        $paciente = Paciente::factory()->create();

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
        Paciente::factory()->create(['dni' => '30111222']);

        $this->postJson('/api/pacientes', ['dni' => '030111222', 'nombre' => 'A', 'apellido' => 'B', 'telefono' => '2254000000'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('dni');
    }

    public function test_patient_update_applies_the_same_rules_as_create(): void
    {
        $paciente = Paciente::factory()->create();

        $this->patchJson("/api/pacientes/{$paciente->dni}", ['telefono' => 'abc<script>'])
            ->assertStatus(422)->assertJsonValidationErrors('telefono');
        $this->patchJson("/api/pacientes/{$paciente->dni}", ['ciclo_actual' => 99999999999])
            ->assertStatus(422)->assertJsonValidationErrors('ciclo_actual');
    }

    public function test_partial_cycle_override_is_rejected(): void
    {
        $this->donar(Paciente::factory()->create(), ['ciclo' => 5])->assertStatus(422);
    }

    public function test_cycle_override_respects_series_and_donation_limits(): void
    {
        $this->donar(Paciente::factory()->create(), ['ciclo' => 1, 'serie' => 4, 'donacion' => 1])
            ->assertStatus(422)->assertJsonValidationErrors('serie');
        $this->donar(Paciente::factory()->create(), ['ciclo' => 1, 'serie' => 1, 'donacion' => 6])
            ->assertStatus(422)->assertJsonValidationErrors('donacion');
    }

    public function test_impossible_clinical_values_are_rejected(): void
    {
        $paciente = Paciente::factory()->create();

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

    public function test_api_bounds_match_column_precision(): void
    {
        $paciente = Paciente::factory()->create();

        $this->donar($paciente, ['hematocrito' => 100])
            ->assertStatus(422)->assertJsonValidationErrors('hematocrito');
        $this->donar($paciente, ['componentes' => [['tipo_id' => $this->sangre->id, 'peso' => 99999999]]])
            ->assertStatus(422)->assertJsonValidationErrors('componentes.0.peso');
    }

    public function test_non_iso_dates_are_rejected(): void
    {
        $paciente = Paciente::factory()->create();

        $this->patchJson("/api/pacientes/{$paciente->dni}/aptitud", ['tipo' => 'APTO_OBSERVACION', 'motivo_id' => $this->motivo->id, 'desde' => '05/01/2026'])
            ->assertStatus(422)->assertJsonValidationErrors('desde');
        $this->postJson("/api/pacientes/{$paciente->dni}/restriccion", ['motivo_id' => $this->motivo->id, 'desde' => '2026-05-01 10:00:00'])
            ->assertStatus(422)->assertJsonValidationErrors('desde');
        $this->postJson("/api/pacientes/{$paciente->dni}/habilitacion-plaquetas", ['desde' => '9999-12-31'])
            ->assertStatus(422)->assertJsonValidationErrors('desde');
        $this->getJson('/api/donaciones?hasta=12/31/2030')->assertStatus(422);
    }

    public function test_permanent_no_apto_cannot_have_an_end_date(): void
    {
        Aptitud::firstOrCreate(['tipo' => 'NO_APTO']);
        $paciente = Paciente::factory()->create();

        $this->patchJson("/api/pacientes/{$paciente->dni}/aptitud", [
            'tipo' => 'NO_APTO', 'motivo_id' => $this->motivo->id, 'desde' => '2026-10-01', 'permanente' => true, 'hasta' => '2027-01-01',
        ])->assertStatus(422)->assertJsonValidationErrors('hasta');
    }

    public function test_api_rejects_same_day_and_open_ended_temporary_restrictions(): void
    {
        $url = '/api/pacientes/'.Paciente::factory()->create()->dni.'/restriccion';

        $this->postJson($url, ['motivo_id' => $this->motivo->id, 'desde' => '2026-10-22', 'hasta' => '2026-10-22'])
            ->assertStatus(422)->assertJsonValidationErrors('hasta');
        $this->postJson($url, ['motivo_id' => $this->motivo->id, 'desde' => '2026-10-22', 'permanente' => false])
            ->assertStatus(422)->assertJsonValidationErrors('hasta');
    }

    public function test_same_day_observation_is_still_allowed_via_aptitud(): void
    {
        Aptitud::firstOrCreate(['tipo' => 'APTO_OBSERVACION']);
        $paciente = Paciente::factory()->create();

        $this->patchJson("/api/pacientes/{$paciente->dni}/aptitud", [
            'tipo' => 'APTO_OBSERVACION', 'motivo_id' => $this->motivo->id, 'desde' => '2026-10-22', 'hasta' => '2026-10-22',
        ])->assertOk();
    }

    public function test_missing_resources_answer_in_spanish_without_class_names(): void
    {
        $this->patchJson('/api/turnos/999999', ['estado' => 'CANCELADO'])
            ->assertNotFound()
            ->assertJsonPath('message', 'No se encontró el recurso solicitado.');
    }

    public function test_validation_summary_is_fully_spanish(): void
    {
        app()->setLocale('es');

        $message = $this->postJson('/api/pacientes', [])->assertStatus(422)->json('message');

        $this->assertStringNotContainsString('more error', $message);
        $this->assertStringContainsString('y 3 errores más', $message);
    }
}
