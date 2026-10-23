<?php

namespace Tests\Feature;

use App\Models\Motivo;
use App\Models\Paciente;
use App\Models\Sede;
use App\Models\TipoDonacion;
use App\Models\Turno;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\ActsAsStaff;
use Tests\TestCase;

/**
 * Rules that used to live only in PHP are also enforced by the database, so a
 * raw write (tinker, import, another client) cannot store nonsense; plus the
 * schema hygiene the query paths and confidentiality rely on.
 */
class DatabaseIntegrityTest extends TestCase
{
    use ActsAsStaff, RefreshDatabase;

    private Paciente $paciente;

    private Motivo $motivo;

    private Sede $sede;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-22 13:00:00');
        $this->sede = Sede::porDefecto();
        $this->sede->update(['codigo_localidad' => '1']);
        $this->motivo = Motivo::factory()->create();
        $this->paciente = Paciente::factory()->create(['dni' => '30111222']);
        $this->actingAsStaff($this->sede);
    }

    private function assertRejected(callable $write): void
    {
        try {
            $write();
        } catch (QueryException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('The database accepted an invalid row.');
    }

    public function test_restriccion_shape_is_enforced_by_the_database(): void
    {
        $base = ['paciente_id' => $this->paciente->id, 'motivo_id' => $this->motivo->id, 'desde' => '2026-10-01', 'created_at' => now(), 'updated_at' => now()];

        $this->assertRejected(fn () => DB::table('restricciones')->insert([...$base, 'permanente' => false, 'hasta' => null]));
        $this->assertRejected(fn () => DB::table('restricciones')->insert([...$base, 'permanente' => true, 'hasta' => '2026-12-01']));
        $this->assertRejected(fn () => DB::table('restricciones')->insert([...$base, 'permanente' => false, 'hasta' => '2026-09-01']));
    }

    public function test_date_windows_cannot_end_before_they_start(): void
    {
        $this->assertRejected(fn () => DB::table('observaciones')->insert(['paciente_id' => $this->paciente->id, 'motivo_id' => $this->motivo->id, 'desde' => '2026-10-10', 'hasta' => '2026-10-01', 'created_at' => now(), 'updated_at' => now()]));
        $this->assertRejected(fn () => DB::table('habilitaciones_plaquetas')->insert(['paciente_id' => $this->paciente->id, 'desde' => '2026-10-10', 'hasta' => '2026-10-01', 'created_at' => now(), 'updated_at' => now()]));
    }

    public function test_enum_like_columns_and_counters_are_enforced(): void
    {
        $this->assertRejected(fn () => DB::table('turnos')->insert(['paciente_id' => $this->paciente->id, 'sede_id' => $this->sede->id, 'fecha' => '2026-11-01', 'hora' => '09:00', 'estado' => 'LO_QUE_SEA', 'created_at' => now(), 'updated_at' => now()]));
        $this->assertRejected(fn () => DB::table('pacientes')->where('id', $this->paciente->id)->update(['sexo' => 'Z']));
        $this->assertRejected(fn () => DB::table('pacientes')->where('id', $this->paciente->id)->update(['ciclo_actual' => -5]));
        $this->assertRejected(fn () => DB::table('pacientes')->where('id', $this->paciente->id)->update(['dni' => '12.345.678']));
    }

    public function test_locality_codes_are_normalised_to_three_digits(): void
    {
        $this->assertSame('001', $this->sede->fresh()->codigo_localidad);

        $this->assertRejected(fn () => Sede::create(['nombre' => 'Duplicada', 'activa' => true, 'codigo_localidad' => '001']));
        $this->assertRejected(fn () => DB::table('sedes')->insert(['nombre' => 'Rara', 'activa' => true, 'codigo_localidad' => '1234', 'created_at' => now(), 'updated_at' => now()]));
    }

    public function test_invalid_or_colliding_locality_codes_never_reach_numbering(): void
    {
        // "1" and "001" are the same code once padded; the DB rejects a collision.
        $this->expectException(QueryException::class);
        Sede::create(['nombre' => 'Pinamar', 'activa' => true, 'codigo_localidad' => '001']);
    }

    public function test_two_active_turnos_still_cannot_share_a_slot_at_db_level(): void
    {
        Turno::factory()->create(['sede_id' => $this->sede->id, 'fecha' => '2026-11-03', 'hora' => '09:00']);

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('turnos')->insert(['paciente_id' => Paciente::factory()->create()->id, 'sede_id' => $this->sede->id, 'fecha' => '2026-11-03', 'hora' => '09:00', 'estado' => 'PENDIENTE', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_self_exclusion_does_not_leak_into_components_or_plain_text(): void
    {
        $tipo = TipoDonacion::factory()->sangre()->create();
        $this->postJson('/api/donaciones', ['paciente_id' => $this->paciente->id, 'tipo_id' => $tipo->id, 'componentes' => [$tipo->id]])
            ->assertCreated();
        $numero = DB::table('donaciones')->value('numero_donacion');

        $this->postJson("/api/donaciones/{$numero}/autoexclusion", ['motivo' => 'Conducta de riesgo reciente'])->assertCreated();

        $this->assertSame('DESCARTE_CONFIDENCIAL', DB::table('componentes_donacion')->value('motivo_descarte'));
        $this->assertStringNotContainsString('Conducta de riesgo', (string) DB::table('autoexclusiones')->value('motivo'));
    }

    public function test_unused_framework_user_tables_are_gone(): void
    {
        $this->assertFalse(Schema::hasTable('users'));
        $this->assertFalse(Schema::hasTable('password_reset_tokens'));
    }

    public function test_query_paths_are_indexed(): void
    {
        $this->assertTrue(Schema::hasIndex('donaciones', ['paciente_id', 'fecha']));
        $this->assertTrue(Schema::hasIndex('observaciones', ['paciente_id']));
        $this->assertTrue(Schema::hasIndex('autorizaciones_extraordinarias', ['paciente_id']));
        $this->assertTrue(Schema::hasIndex('recordatorios_turno', ['turno_id']));
        $this->assertTrue(Schema::hasIndex('componentes_donacion', ['tipo_id']));
    }
}
