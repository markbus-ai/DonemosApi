<?php

namespace Tests\Feature;

use App\Models\Aptitud;
use App\Models\AutorizacionExtraordinaria;
use App\Models\Motivo;
use App\Models\Paciente;
use App\Models\Restriccion;
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
 * Second abuse pass: deferral history, local-day handling outside turnos and
 * donations, donation numbering and loose date formats.
 */
class DemoHardening2Test extends TestCase
{
    use RefreshDatabase;

    private Sede $sede;

    private Motivo $motivo;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.business_timezone' => 'America/Argentina/Buenos_Aires']);
        $this->travelTo('2026-10-22 13:00:00');

        Aptitud::firstOrCreate(['tipo' => 'APTO']);
        Aptitud::firstOrCreate(['tipo' => 'NO_APTO']);
        Aptitud::firstOrCreate(['tipo' => 'APTO_OBSERVACION']);
        $this->sede = Sede::firstOrCreate(['nombre' => 'Sede Central'], ['activa' => true]);
        $this->sede->update(['codigo_localidad' => '1']);
        $this->motivo = Motivo::firstOrCreate(['nombre' => 'Prueba']);

        $staff = Usuario::create([
            'username' => 'staff_'.uniqid(),
            'password_hash' => Hash::make('1234'),
            'rol_id' => Rol::firstOrCreate(['nombre' => 'staff'])->id,
            'sede_id' => $this->sede->id,
        ]);

        Sanctum::actingAs($staff, ['*'], 'staff');
    }

    private function paciente(): Paciente
    {
        return Paciente::create([
            'dni' => (string) fake()->unique()->numerify('########'),
            'nombre' => 'Ana',
            'apellido' => 'Gomez',
            'telefono' => '2254000000',
            'sexo' => 'F',
            'aptitud_id' => Aptitud::where('tipo', 'APTO')->value('id'),
        ]);
    }

    private function sangre(): TipoDonacion
    {
        return TipoDonacion::firstOrCreate(['codigo' => 'SANGRE'], ['nombre' => 'SANGRE']);
    }

    public function test_backdated_deferral_cannot_lift_an_active_one(): void
    {
        $paciente = $this->paciente();
        $this->postJson("/api/pacientes/{$paciente->dni}/restriccion", ['motivo_id' => $this->motivo->id, 'desde' => '2026-04-01'])
            ->assertCreated();

        $this->postJson("/api/pacientes/{$paciente->dni}/restriccion", ['motivo_id' => $this->motivo->id, 'desde' => '2020-01-01', 'hasta' => '2020-01-02'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('desde');

        $this->patchJson("/api/pacientes/{$paciente->dni}/aptitud", ['tipo' => 'NO_APTO', 'motivo_id' => $this->motivo->id, 'desde' => '2020-01-01'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('desde');

        $this->assertTrue(Restriccion::where('paciente_id', $paciente->id)->vigente('2026-10-22')->exists());
    }

    public function test_disabling_platelets_in_the_argentine_evening_takes_effect_today(): void
    {
        $paciente = $this->paciente();
        $this->postJson("/api/pacientes/{$paciente->dni}/habilitacion-plaquetas", [])->assertCreated();

        // 22:30 ART is already the next day in UTC.
        $this->travelTo('2026-10-23 01:30:00');
        $this->deleteJson("/api/pacientes/{$paciente->dni}/habilitacion-plaquetas")->assertNoContent();

        $this->assertDatabaseHas('habilitaciones_plaquetas', ['paciente_id' => $paciente->id, 'hasta' => '2026-10-22']);
    }

    public function test_lifting_a_deferral_in_the_evening_closes_it_today(): void
    {
        $paciente = $this->paciente();
        $this->postJson("/api/pacientes/{$paciente->dni}/restriccion", ['motivo_id' => $this->motivo->id, 'desde' => '2026-10-01'])
            ->assertCreated();

        $this->travelTo('2026-10-23 01:30:00');
        $this->patchJson("/api/pacientes/{$paciente->dni}/aptitud", ['tipo' => 'APTO'])->assertOk();

        $this->assertDatabaseHas('restricciones', ['paciente_id' => $paciente->id, 'hasta' => '2026-10-22']);
    }

    public function test_invalid_or_colliding_locality_codes_never_reach_numbering(): void
    {
        // "1" and "001" are the same code once padded; the DB rejects both a
        // collision and an over-long code, so allocation never mints duplicates.
        $this->expectException(\Illuminate\Database\QueryException::class);
        Sede::create(['nombre' => 'Pinamar', 'activa' => true, 'codigo_localidad' => '001']);
    }

    public function test_non_iso_dates_are_rejected(): void
    {
        $paciente = $this->paciente();

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
        $paciente = $this->paciente();

        $this->patchJson("/api/pacientes/{$paciente->dni}/aptitud", [
            'tipo' => 'NO_APTO', 'motivo_id' => $this->motivo->id, 'desde' => '2026-10-01', 'permanente' => true, 'hasta' => '2027-01-01',
        ])->assertStatus(422)->assertJsonValidationErrors('hasta');
    }

    public function test_forced_turno_keeps_the_staff_motivo(): void
    {
        $paciente = $this->paciente();
        Turno::create(['paciente_id' => $paciente->id, 'sede_id' => $this->sede->id, 'fecha' => '2026-11-01', 'hora' => '09:00', 'estado' => 'PENDIENTE']);

        $this->postJson('/api/turnos', ['paciente_id' => $paciente->id, 'fecha' => '2026-11-02', 'hora' => '09:00', 'forzar' => true, 'motivo' => 'Pedido del médico de guardia'])
            ->assertCreated();

        $this->assertStringContainsString('Pedido del médico de guardia', AutorizacionExtraordinaria::latest('id')->value('motivo'));
    }

    public function test_patient_update_applies_the_same_rules_as_create(): void
    {
        $paciente = $this->paciente();

        $this->patchJson("/api/pacientes/{$paciente->dni}", ['telefono' => 'abc<script>'])
            ->assertStatus(422)->assertJsonValidationErrors('telefono');
        $this->patchJson("/api/pacientes/{$paciente->dni}", ['ciclo_actual' => 99999999999])
            ->assertStatus(422)->assertJsonValidationErrors('ciclo_actual');
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
