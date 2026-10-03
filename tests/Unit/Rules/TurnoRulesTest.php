<?php

namespace Tests\Unit\Rules;

use App\Models\Aptitud;
use App\Models\Donacion;
use App\Models\Paciente;
use App\Models\TipoDonacion;
use App\Models\Turno;
use App\Rules\DonationRules;
use App\Rules\TurnoRules;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TurnoRulesTest extends TestCase
{
    use RefreshDatabase;

    private function createAptitud(string $tipo = 'APTO'): Aptitud
    {
        return Aptitud::create(['tipo' => $tipo]);
    }

    private function createTipo(string $codigo = 'PLAQUETAS'): TipoDonacion
    {
        return TipoDonacion::firstOrCreate(['codigo' => $codigo], ['nombre' => $codigo]);
    }

    private function createPaciente(Aptitud $aptitud, array $overrides = []): Paciente
    {
        return Paciente::create(array_merge([
            'dni' => (string) fake()->unique()->numerify('########'),
            'nombre' => fake()->firstName(),
            'apellido' => fake()->lastName(),
            'telefono' => fake()->phoneNumber(),
            'aptitud_id' => $aptitud->id,
        ], $overrides));
    }

    private function makeTurnoRules(): TurnoRules
    {
        return new TurnoRules(new DonationRules);
    }

    /** @test */
    public function test_turno_valido_sin_historial_ok(): void
    {
        $aptitud = $this->createAptitud();
        $this->createTipo('PLAQUETAS');
        $this->createTipo('PLASMA');
        $paciente = $this->createPaciente($aptitud);

        $fecha = Carbon::tomorrow()->toDateString();

        $rules = $this->makeTurnoRules();
        $result = $rules->check($paciente, $fecha, '10:00');

        $this->assertTrue($result['allowed']);
        $this->assertSame([], $result['warnings']);
    }

    /**
     * Turno dentro del intervalo de donación (donación hace 1 día plaquetas, turno mañana)
     * debe retornar WARNING_INTERVALO vía DonationRules.
     *
     * @test
     */
    public function test_turno_dentro_intervalo_donacion_warning_intervalo(): void
    {
        $aptitud = $this->createAptitud();
        $tipoPlaquetas = $this->createTipo('PLAQUETAS');
        $this->createTipo('PLASMA');
        $paciente = $this->createPaciente($aptitud);

        $fechaTurno = Carbon::tomorrow()->toDateString();
        $fechaDonacion = Carbon::parse($fechaTurno)->subDay()->toDateString();

        Donacion::create([
            'paciente_id' => $paciente->id,
            'tipo_id' => $tipoPlaquetas->id,
            'fecha' => $fechaDonacion,
        ]);

        $rules = $this->makeTurnoRules();
        $result = $rules->check($paciente, $fechaTurno, '10:00', $tipoPlaquetas->id);

        $this->assertFalse($result['allowed']);
        $codes = array_column($result['warnings'], 'code');
        $this->assertContains('WARNING_INTERVALO', $codes);
    }

    /** @test */
    public function test_turno_cancelado_no_afecta(): void
    {
        $aptitud = $this->createAptitud();
        $this->createTipo('PLAQUETAS');
        $this->createTipo('PLASMA');
        $paciente = $this->createPaciente($aptitud);

        Turno::create([
            'paciente_id' => $paciente->id,
            'fecha' => Carbon::tomorrow()->toDateString(),
            'hora' => '10:00:00',
            'estado' => 'CANCELADO',
        ]);

        $fechaNuevoTurno = Carbon::tomorrow()->addDay()->toDateString();
        $rules = $this->makeTurnoRules();
        $result = $rules->check($paciente, $fechaNuevoTurno, '11:00');

        $this->assertTrue($result['allowed'], 'Turno CANCELADO no debe generar warning');
        $this->assertSame([], $result['warnings']);
    }

    /** @test */
    public function test_turno_pendiente_afecta_warning_turno_pendiente_existente(): void
    {
        $aptitud = $this->createAptitud();
        $this->createTipo('PLAQUETAS');
        $this->createTipo('PLASMA');
        $paciente = $this->createPaciente($aptitud);

        Turno::create([
            'paciente_id' => $paciente->id,
            'fecha' => Carbon::tomorrow()->toDateString(),
            'hora' => '10:00:00',
            'estado' => 'PENDIENTE',
        ]);

        $fechaNuevoTurno = Carbon::tomorrow()->addDays(2)->toDateString();
        $rules = $this->makeTurnoRules();
        $result = $rules->check($paciente, $fechaNuevoTurno, '11:00');

        $this->assertFalse($result['allowed']);
        $codes = array_column($result['warnings'], 'code');
        $this->assertContains('TURNO_PENDIENTE_EXISTENTE', $codes);
    }

    /** @test */
    public function test_multiples_turnos_futuros_se_evaluan(): void
    {
        $aptitud = $this->createAptitud();
        $this->createTipo('PLAQUETAS');
        $this->createTipo('PLASMA');
        $paciente = $this->createPaciente($aptitud);

        Turno::create([
            'paciente_id' => $paciente->id,
            'fecha' => Carbon::tomorrow()->toDateString(),
            'hora' => '09:00:00',
            'estado' => 'PENDIENTE',
        ]);

        $rules = $this->makeTurnoRules();
        $result = $rules->check($paciente, Carbon::tomorrow()->addDays(3)->toDateString(), '11:00');

        $codes = array_column($result['warnings'], 'code');
        $this->assertContains('TURNO_PENDIENTE_EXISTENTE', $codes);
        $this->assertFalse($result['allowed']);
    }

    /** @test */
    public function test_turno_atendido_no_afecta(): void
    {
        $aptitud = $this->createAptitud();
        $this->createTipo('PLAQUETAS');
        $this->createTipo('PLASMA');
        $paciente = $this->createPaciente($aptitud);

        Turno::create([
            'paciente_id' => $paciente->id,
            'fecha' => Carbon::tomorrow()->toDateString(),
            'hora' => '10:00:00',
            'estado' => 'ATENDIDO',
        ]);

        $rules = $this->makeTurnoRules();
        $result = $rules->check($paciente, Carbon::tomorrow()->addDays(2)->toDateString(), '11:00');

        $this->assertTrue($result['allowed'], 'Turno ATENDIDO no debe generar warning');
        $this->assertSame([], $result['warnings']);
    }

    /** @test */
    public function test_sin_tipo_id_evalua_ambos_tipos_y_mergea_warnings(): void
    {
        $aptitud = $this->createAptitud();
        $tipoPlaquetas = $this->createTipo('PLAQUETAS');
        $tipoPlasma = $this->createTipo('PLASMA');
        $paciente = $this->createPaciente($aptitud);

        // Donación de plaquetas hace 1 día antes del turno solicitado -> debe generar WARNING_INTERVALO
        // cuando se evalúa sin tipoId (mergea ambos tipos, al menos uno falla)
        $fechaTurno = Carbon::tomorrow()->toDateString();
        $fechaDonacion = Carbon::parse($fechaTurno)->subDay()->toDateString();

        Donacion::create([
            'paciente_id' => $paciente->id,
            'tipo_id' => $tipoPlaquetas->id,
            'fecha' => $fechaDonacion,
        ]);

        $rules = $this->makeTurnoRules();
        // tipoId null => evalúa con ambos tipos
        $result = $rules->check($paciente, $fechaTurno, '10:00', null);

        $codes = array_column($result['warnings'], 'code');
        $this->assertContains('WARNING_INTERVALO', $codes);
        $this->assertFalse($result['allowed']);
    }

    /**
     * Without an explicit type, candidates are enumerated by codigo.
     *
     * @test
     */
    public function test_sin_tipo_evalua_candidatos_por_codigo(): void
    {
        $aptitud = $this->createAptitud();
        $tipoPlaquetas = TipoDonacion::firstOrCreate(
            ['codigo' => 'PLAQUETAS'],
            ['nombre' => 'AFERESIS PLAQUETARIA']
        );
        TipoDonacion::firstOrCreate(['codigo' => 'SANGRE'], ['nombre' => 'SANGRE']);
        $paciente = $this->createPaciente($aptitud);

        $fechaTurno = Carbon::tomorrow()->toDateString();

        Donacion::create([
            'paciente_id' => $paciente->id,
            'tipo_id' => $tipoPlaquetas->id,
            'fecha' => Carbon::parse($fechaTurno)->subDay()->toDateString(),
        ]);

        $rules = $this->makeTurnoRules();
        $result = $rules->check($paciente, $fechaTurno, '10:00', null);

        $codes = array_column($result['warnings'], 'code');
        $this->assertContains('WARNING_INTERVALO', $codes);
        $this->assertFalse($result['allowed']);
    }

    // Nota: Para el caso "forzar permite continuar" no testear acá.
    // TurnoRules es puro: solo retorna warnings. El forzar lo maneja TurnoService
    // en su flujo two-step con transacción (409 sin forzar, 201 con forzar + autorización).
}
