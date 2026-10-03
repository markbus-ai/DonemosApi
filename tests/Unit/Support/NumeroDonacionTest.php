<?php

namespace Tests\Unit\Support;

use App\Models\Sede;
use App\Support\NumeroDonacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Number formatter and concurrency-safe allocator.
 *
 * The allocator MUST run inside the caller's transaction; these tests exercise
 * the counter table directly to prove first/increment/per-sede-year behaviour.
 */
class NumeroDonacionTest extends TestCase
{
    use RefreshDatabase;

    private function sede(?string $codigo = '1'): Sede
    {
        return Sede::create([
            'nombre' => 'Sede '.($codigo ?? 'sin-codigo').'-'.uniqid(),
            'codigo_localidad' => $codigo,
            'activa' => true,
        ]);
    }

    private function central(): Sede
    {
        return Sede::where('nombre', 'Sede Central')->firstOrFail();
    }

    public function test_format_pads_year_locality_and_sequence(): void
    {
        $this->assertSame('2026001000001', NumeroDonacion::format(2026, '1', 1));
    }

    public function test_format_keeps_three_digit_locality_code(): void
    {
        $this->assertSame('2026003000007', NumeroDonacion::format(2026, '003', 7));
    }

    public function test_allocate_starts_counter_at_one(): void
    {
        $sede = $this->central();

        $numero = NumeroDonacion::allocate($sede, 2026);

        $this->assertSame('2026001000001', $numero);
        $this->assertSame(1, DB::table('secuencias_donacion')
            ->where('sede_id', $sede->id)
            ->where('anio', 2026)
            ->value('ultimo_numero'));
    }

    public function test_allocate_increments_within_same_sede_and_year(): void
    {
        $sede = $this->central();

        $first = NumeroDonacion::allocate($sede, 2026);
        $second = NumeroDonacion::allocate($sede, 2026);

        $this->assertSame('2026001000001', $first);
        $this->assertSame('2026001000002', $second);
        $this->assertSame(1, DB::table('secuencias_donacion')
            ->where('sede_id', $sede->id)
            ->where('anio', 2026)
            ->count());
    }

    public function test_allocate_counts_per_sede_and_year(): void
    {
        $central = $this->central();
        $pinamar = $this->sede('003');

        $this->assertSame('2026001000001', NumeroDonacion::allocate($central, 2026));
        $this->assertSame('2026003000001', NumeroDonacion::allocate($pinamar, 2026));
        $this->assertSame('2027001000001', NumeroDonacion::allocate($central, 2027));
    }

    public function test_allocate_fails_closed_without_locality_code(): void
    {
        $sede = $this->sede(null);

        try {
            NumeroDonacion::allocate($sede, 2026);
            $this->fail('Expected ValidationException for a sede without a locality code.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('sede_id', $e->errors());
        }

        $this->assertSame(0, DB::table('secuencias_donacion')->count());
    }
}
