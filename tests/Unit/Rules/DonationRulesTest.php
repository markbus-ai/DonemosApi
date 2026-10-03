<?php

namespace Tests\Unit\Rules;

use App\Models\Aptitud;
use App\Models\Donacion;
use App\Models\Paciente;
use App\Models\TipoDonacion;
use App\Models\Turno;
use App\Rules\DonationRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DonationRulesTest extends TestCase
{
    use RefreshDatabase;

    private function createAptitud(string $tipo = 'APTO'): Aptitud
    {
        return Aptitud::create(['tipo' => $tipo]);
    }

    private function createTipo(string $codigo = 'PLAQUETAS'): TipoDonacion
    {
        return TipoDonacion::create(['nombre' => $codigo, 'codigo' => $codigo]);
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

    /** @test */
    public function test_paciente_sin_donaciones_allowed_true_warnings_empty(): void
    {
        $aptitud = $this->createAptitud();
        $tipo = $this->createTipo('PLAQUETAS');
        $paciente = $this->createPaciente($aptitud);

        $rules = new DonationRules;
        $result = $rules->check($paciente, $tipo, now()->toDateString());

        $this->assertTrue($result['allowed']);
        $this->assertSame([], $result['warnings']);
    }

    /**
     * Res. 536/2026 - PLAQUETAS intervalo < 48 horas -> WARNING_INTERVALO
     *
     * @test
     */
    public function test_ultima_donacion_demasiado_reciente_warning_intervalo(): void
    {
        $aptitud = $this->createAptitud();
        $tipoPlaquetas = $this->createTipo('PLAQUETAS');
        $paciente = $this->createPaciente($aptitud);

        // Última donación hace 1 día ( < 48h ), solicitud hoy -> debe dar WARNING_INTERVALO
        Donacion::create([
            'paciente_id' => $paciente->id,
            'tipo_id' => $tipoPlaquetas->id,
            'fecha' => now()->subDay()->toDateString(),
        ]);

        $rules = new DonationRules;
        $result = $rules->check($paciente, $tipoPlaquetas, now()->toDateString());

        $this->assertFalse($result['allowed']);
        $this->assertNotEmpty($result['warnings']);
        $codes = array_column($result['warnings'], 'code');
        $this->assertContains('WARNING_INTERVALO', $codes);
    }

    /**
     * Res. 536/2026 - Límite anual >24 en 12 meses -> WARNING_LIMITE_ANUAL
     *
     * @test
     */
    public function test_limite_anual_alcanzado_warning_limite_anual(): void
    {
        $aptitud = $this->createAptitud();
        $tipo = $this->createTipo('PLAQUETAS');
        $paciente = $this->createPaciente($aptitud);

        // Crear 24 donaciones dentro de los últimos 12 meses; la 25ª (solicitud actual) supera el límite
        for ($i = 0; $i < 24; $i++) {
            Donacion::create([
                'paciente_id' => $paciente->id,
                'tipo_id' => $tipo->id,
                'fecha' => now()->subMonths(11)->addDays($i * 5)->toDateString(),
            ]);
        }

        $rules = new DonationRules;
        $result = $rules->check($paciente, $tipo, now()->toDateString());

        $this->assertFalse($result['allowed']);
        $codes = array_column($result['warnings'], 'code');
        $this->assertContains('WARNING_LIMITE_ANUAL', $codes);
    }

    /**
     * Res. 536/2026 - Varias reglas incumplidas simultáneamente
     *
     * @test
     */
    public function test_varias_reglas_incumplidas_devuelve_todos_los_warnings(): void
    {
        $aptitud = $this->createAptitud();
        $tipo = $this->createTipo('PLAQUETAS');
        $paciente = $this->createPaciente($aptitud);

        // 24 donaciones en el último año para provocar WARNING_LIMITE_ANUAL
        for ($i = 0; $i < 24; $i++) {
            Donacion::create([
                'paciente_id' => $paciente->id,
                'tipo_id' => $tipo->id,
                'fecha' => now()->subMonths(6)->addDays($i * 2)->toDateString(),
            ]);
        }
        // Última donación hace 1 día para provocar WARNING_INTERVALO (48h)
        // Se crea después del loop para que sea la más reciente dentro de 48h
        Donacion::create([
            'paciente_id' => $paciente->id,
            'tipo_id' => $tipo->id,
            'fecha' => now()->subDay()->toDateString(),
        ]);

        $result = (new DonationRules)->check($paciente, $tipo, now()->toDateString());

        $codes = array_column($result['warnings'], 'code');
        $this->assertFalse($result['allowed']);
        $this->assertContains('WARNING_INTERVALO', $codes);
        $this->assertContains('WARNING_LIMITE_ANUAL', $codes);
        $this->assertGreaterThanOrEqual(2, count($result['warnings']));
    }

    // -----------------------------------------------------------------
    // Tests que quedan SKIPPED: dependen de datos clínicos no presentes
    // en el modelo actual (IgG/proteínas para plasma seriado, y regla
    // cruzada sangre/doble producto/aféresis sin retorno -> 4 semanas).
    // Ver TODOs en App\Rules\DonationRules. No hardcodear aptitud médica.
    // -----------------------------------------------------------------

    /**
     * @test
     */
    public function test_plasma_seriado_igg_no_implementable_skipped(): void
    {
        $this->markTestSkipped('Requiere controles clínicos IgG/proteínas/albúmina no presentes en DB (Res. 536/2026 plasma seriado). Queda a criterio médico/autorización manual.');
    }

    /**
     * @test
     */
    public function test_regla_cruzada_sangre_a_plaquetas_no_implementable_skipped(): void
    {
        $this->markTestSkipped('Requiere tipos SANGRE/DOBLE_PRODUCTO/AFERESIS_SIN_RETORNO no existentes en tipos_donacion. Se implementará al ampliar catálogo (4 semanas post sangre -> plaquetas/plasma).');
    }

    /**
     * Nota: DonationRules evalúa Donaciones, no Turnos.
     * Este test verifica que turnos cancelados NO afectan la evaluación.
     * Si Turnos deben participar en la regla, mover lógica a TurnoRules
     * o ampliar DonationRules para consultar Turnos con estado relevante.
     */
    public function test_turno_cancelado_no_participa(): void
    {
        $aptitud = $this->createAptitud();
        $tipo = $this->createTipo('PLAQUETAS');
        $paciente = $this->createPaciente($aptitud);

        // Turno cancelado reciente no debe afectar
        Turno::create([
            'paciente_id' => $paciente->id,
            'fecha' => now()->subDays(2)->toDateString(),
            'hora' => '10:00:00',
            'estado' => 'CANCELADO',
        ]);

        $rules = new DonationRules;
        $result = $rules->check($paciente, $tipo, now()->toDateString());

        $this->assertTrue($result['allowed'], 'Turno CANCELADO no debe generar warning en DonationRules');
        $this->assertSame([], $result['warnings']);
    }

    /**
     * Nota: DonationRules evalúa Donaciones, no Turnos.
     * Este test verifica que turnos pendientes actualmente tampoco afectan.
     * Si Turnos deben participar según reglas de negocio (ej. turno pendiente
     * cuenta para intervalo), mover lógica a TurnoRules o extender DonationRules.
     */
    public function test_turno_pendiente_no_afecta_donation_rules_actual(): void
    {
        $aptitud = $this->createAptitud();
        $tipo = $this->createTipo('PLAQUETAS');
        $paciente = $this->createPaciente($aptitud);

        Turno::create([
            'paciente_id' => $paciente->id,
            'fecha' => now()->subDays(2)->toDateString(),
            'hora' => '10:00:00',
            'estado' => 'PENDIENTE',
        ]);

        $rules = new DonationRules;
        $result = $rules->check($paciente, $tipo, now()->toDateString());

        // Comportamiento actual: DonationRules solo mira Donaciones, por lo que sigue allowed true.
        $this->assertTrue($result['allowed'], 'Turno PENDIENTE actualmente no afecta DonationRules (solo Donaciones)');
        $this->assertSame([], $result['warnings']);
    }
}
