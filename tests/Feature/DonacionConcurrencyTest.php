<?php

namespace Tests\Feature;

use App\Models\Aptitud;
use App\Models\Paciente;
use App\Models\Sede;
use App\Models\TipoDonacion;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActsAsStaff;
use Tests\TestCase;

/**
 * Two donations of the same donor cannot interleave: the paciente row is
 * locked first and the interval rules plus the cycle odometer are evaluated
 * inside that same transaction.
 */
class DonacionConcurrencyTest extends TestCase
{
    use ActsAsStaff, RefreshDatabase;

    private Paciente $paciente;

    private TipoDonacion $tipo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-22 13:00:00');
        $sede = Sede::firstOrCreate(['nombre' => 'Sede Central'], ['activa' => true]);
        $sede->update(['codigo_localidad' => '1']);
        $this->tipo = TipoDonacion::firstOrCreate(['codigo' => 'SANGRE'], ['nombre' => 'SANGRE']);
        $this->paciente = Paciente::create([
            'dni' => '30111222',
            'nombre' => 'Ana',
            'apellido' => 'Gomez',
            'telefono' => '2254000000',
            'sexo' => 'F',
            'aptitud_id' => Aptitud::firstOrCreate(['tipo' => 'APTO'])->id,
        ]);

        $this->actingAsStaff($sede);
    }

    /**
     * @return array<int, array{sql: string, level: int}>
     */
    private function donarCapturando(): array
    {
        $queries = [];
        $base = DB::transactionLevel();
        DB::listen(function (QueryExecuted $query) use (&$queries, $base) {
            $queries[] = ['sql' => $query->sql, 'level' => DB::transactionLevel() - $base];
        });

        $this->postJson('/api/donaciones', [
            'paciente_id' => $this->paciente->id,
            'tipo_id' => $this->tipo->id,
            'componentes' => [$this->tipo->id],
        ])->assertCreated();

        return $queries;
    }

    public function test_the_donor_is_locked_before_the_rules_read_the_history(): void
    {
        $queries = $this->donarCapturando();

        $lock = collect($queries)->search(fn ($q) => preg_match('/^update "pacientes" set "updated_at" = updated_at/', $q['sql']) === 1);
        $historial = collect($queries)->search(fn ($q) => str_starts_with($q['sql'], 'select * from "donaciones" where "paciente_id"'));

        $this->assertNotFalse($lock, 'the paciente row is never locked');
        $this->assertNotFalse($historial);
        $this->assertLessThan($historial, $lock);
        $this->assertGreaterThan(0, $queries[$lock]['level']);
        $this->assertGreaterThan(0, $queries[$historial]['level']);
    }

    public function test_the_cycle_continues_from_counters_read_under_the_lock(): void
    {
        // Another request advanced the counters after this one loaded the model.
        $this->paciente->update(['ciclo_actual' => 1, 'serie_actual' => 1, 'donacion_actual' => 2]);

        $this->donarCapturando();

        $this->assertSame([1, 1, 3], [$this->paciente->fresh()->ciclo_actual, $this->paciente->fresh()->serie_actual, $this->paciente->fresh()->donacion_actual]);
    }
}
