<?php

namespace Tests\Unit\Models;

use App\Models\Aptitud;
use App\Models\Motivo;
use App\Models\Paciente;
use App\Models\Restriccion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Active-deferral predicate boundaries.
 *
 * Active as of D when `desde <= D` AND (`permanente` OR `hasta IS NULL` OR `D < hasta`).
 * `desde` is inclusive; `hasta` is the return day and is exclusive.
 */
class RestriccionVigenteTest extends TestCase
{
    use RefreshDatabase;

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

    private function motivo(): Motivo
    {
        return Motivo::create(['nombre' => fake()->unique()->word()]);
    }

    private function restriccion(array $overrides = []): Restriccion
    {
        return Restriccion::create(array_merge([
            'paciente_id' => $this->paciente()->id,
            'motivo_id' => $this->motivo()->id,
            'desde' => '2026-01-01',
            'hasta' => '2026-04-01',
            'permanente' => false,
        ], $overrides));
    }

    private function isVigente(Restriccion $restriccion, string $fecha): bool
    {
        return Restriccion::whereKey($restriccion->id)->vigente($fecha)->exists();
    }

    public function test_desde_is_inclusive(): void
    {
        $restriccion = $this->restriccion(['desde' => '2026-01-01', 'hasta' => '2026-04-01']);

        $this->assertTrue($this->isVigente($restriccion, '2026-01-01'));
    }

    public function test_day_before_desde_does_not_match(): void
    {
        $restriccion = $this->restriccion(['desde' => '2026-01-01', 'hasta' => '2026-04-01']);

        $this->assertFalse($this->isVigente($restriccion, '2025-12-31'));
    }

    public function test_day_before_hasta_blocks(): void
    {
        $restriccion = $this->restriccion(['desde' => '2026-01-01', 'hasta' => '2026-04-01']);

        $this->assertTrue($this->isVigente($restriccion, '2026-03-31'));
    }

    public function test_return_day_allows(): void
    {
        $restriccion = $this->restriccion(['desde' => '2026-01-01', 'hasta' => '2026-04-01']);

        $this->assertFalse($this->isVigente($restriccion, '2026-04-01'));
    }

    public function test_permanent_row_matches_far_future(): void
    {
        $restriccion = $this->restriccion(['desde' => '2026-01-01', 'hasta' => null, 'permanente' => true]);

        $this->assertTrue($this->isVigente($restriccion, '2030-01-01'));
    }

    public function test_legacy_null_hasta_row_matches(): void
    {
        $restriccion = $this->restriccion(['desde' => '2026-01-01', 'hasta' => null, 'permanente' => false]);

        $this->assertTrue($this->isVigente($restriccion, '2030-01-01'));
    }
}
