<?php

namespace Tests\Unit\Rules;

use App\Models\Aptitud;
use App\Models\Paciente;
use App\Models\Sede;
use App\Models\TipoDonacion;
use App\Models\Turno;
use App\Rules\CapacidadRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pure duration-sum capacity: daily used minutes for a sede+date are compared
 * against `minutos_por_dia` (spec A1). A candidate occupies
 * `max(duracion, duracion_minima)`; rejection is strict (`used + candidate >
 * limite`) so booking exactly up to the limit is allowed.
 */
class CapacidadRulesTest extends TestCase
{
    use RefreshDatabase;

    private function sede(string $nombre = 'Sede Central'): Sede
    {
        return Sede::firstOrCreate(['nombre' => $nombre], ['activa' => true]);
    }

    private function paciente(): Paciente
    {
        $aptitud = Aptitud::firstOrCreate(['tipo' => 'APTO']);

        return Paciente::create([
            'dni' => (string) fake()->unique()->numerify('########'),
            'nombre' => fake()->firstName(),
            'apellido' => fake()->lastName(),
            'telefono' => fake()->phoneNumber(),
            'aptitud_id' => $aptitud->id,
        ]);
    }

    private function tipo(?int $duracion): TipoDonacion
    {
        return TipoDonacion::create([
            'nombre' => 'T'.uniqid(),
            'codigo' => 'T'.strtoupper(uniqid()),
            'duracion_minutos' => $duracion,
        ]);
    }

    private function turnoConDuracion(Sede $sede, TipoDonacion $tipo, string $fecha, string $hora): Turno
    {
        return Turno::create([
            'paciente_id' => $this->paciente()->id,
            'sede_id' => $sede->id,
            'tipo_id' => $tipo->id,
            'fecha' => $fecha,
            'hora' => $hora,
            'estado' => 'PENDIENTE',
        ]);
    }

    public function test_accepts_when_used_plus_candidate_is_at_the_limit(): void
    {
        $rules = new CapacidadRules(['minutos_por_dia' => 60, 'duracion_minima' => 15]);
        $sede = $this->sede();
        $tipo = $this->tipo(30);

        $this->turnoConDuracion($sede, $tipo, '2026-06-01', '09:00');

        // Used 30 + candidate 30 == 60 → exactly at the limit, allowed.
        $result = $rules->check($sede->id, '2026-06-01', $tipo->id);

        $this->assertTrue($result['allowed']);
        $this->assertSame(30, $result['used']);
        $this->assertSame(60, $result['limite']);
        $this->assertSame(30, $result['candidate']);
        $this->assertSame(30, $result['available']);
    }

    public function test_rejects_when_used_plus_candidate_exceeds_the_limit(): void
    {
        $rules = new CapacidadRules(['minutos_por_dia' => 60, 'duracion_minima' => 15]);
        $sede = $this->sede();
        $tipo = $this->tipo(30);

        $this->turnoConDuracion($sede, $tipo, '2026-06-01', '09:00');

        // Used 30 + candidate 60 == 90 > 60 → blocked.
        $big = $this->tipo(60);
        $result = $rules->check($sede->id, '2026-06-01', $big->id);

        $this->assertFalse($result['allowed']);
        $this->assertSame('CAPACIDAD_EXCEDIDA', $result['code']);
        $this->assertSame(60, $result['candidate']);
    }

    public function test_null_duration_falls_back_to_minima(): void
    {
        $rules = new CapacidadRules(['minutos_por_dia' => 480, 'duracion_minima' => 15]);
        $sede = $this->sede();
        $tipo = $this->tipo(null);

        $result = $rules->check($sede->id, '2026-06-01', $tipo->id);

        $this->assertSame(15, $result['candidate']);
        $this->assertTrue($result['allowed']);
    }

    public function test_short_duration_never_drops_below_minima(): void
    {
        $rules = new CapacidadRules(['minutos_por_dia' => 480, 'duracion_minima' => 15]);
        $sede = $this->sede();
        $tipo = $this->tipo(5);

        $this->assertSame(15, $rules->check($sede->id, '2026-06-01', $tipo->id)['candidate']);
    }

    public function test_missing_tipo_uses_minima_as_candidate(): void
    {
        $rules = new CapacidadRules(['minutos_por_dia' => 480, 'duracion_minima' => 15]);
        $sede = $this->sede();

        $this->assertSame(15, $rules->check($sede->id, '2026-06-01', null)['candidate']);
    }

    public function test_capacity_is_isolated_per_sede(): void
    {
        $rules = new CapacidadRules(['minutos_por_dia' => 60, 'duracion_minima' => 15]);
        $sedeA = $this->sede('Sede Central');
        $sedeB = $this->sede('Pinamar');
        $tipo = $this->tipo(60);

        $this->turnoConDuracion($sedeA, $tipo, '2026-06-01', '09:00');

        // Sede A is at its limit; sede B is untouched.
        $this->assertFalse($rules->check($sedeA->id, '2026-06-01', $tipo->id)['allowed']);
        $this->assertTrue($rules->check($sedeB->id, '2026-06-01', $tipo->id)['allowed']);
    }

    public function test_used_minutes_ignore_other_dates(): void
    {
        $rules = new CapacidadRules(['minutos_por_dia' => 60, 'duracion_minima' => 15]);
        $sede = $this->sede();
        $tipo = $this->tipo(60);

        $this->turnoConDuracion($sede, $tipo, '2026-05-31', '09:00');

        // The prior day's usage must not leak into 2026-06-01.
        $this->assertSame(0, $rules->check($sede->id, '2026-06-01', $tipo->id)['used']);
    }

    public function test_legacy_turno_without_tipo_counts_minima(): void
    {
        $rules = new CapacidadRules(['minutos_por_dia' => 60, 'duracion_minima' => 15]);
        $sede = $this->sede();

        Turno::create([
            'paciente_id' => $this->paciente()->id,
            'sede_id' => $sede->id,
            'fecha' => '2026-06-01',
            'hora' => '09:00',
            'estado' => 'PENDIENTE',
        ]);

        $this->assertSame(15, $rules->check($sede->id, '2026-06-01', null)['used']);
    }

    public function test_defaults_are_read_from_config(): void
    {
        config(['agenda_capacidad.minutos_por_dia' => 300, 'agenda_capacidad.duracion_minima' => 20]);

        $rules = new CapacidadRules;
        $sede = $this->sede();

        $result = $rules->check($sede->id, '2026-06-01', null);

        $this->assertSame(300, $result['limite']);
        $this->assertSame(20, $result['candidate']);
    }
}
