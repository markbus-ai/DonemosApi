<?php

namespace Tests\Feature;

use App\Jobs\EnviarRecordatorioWhatsApp;
use App\Models\Aptitud;
use App\Models\Paciente;
use App\Models\RecordatorioTurno;
use App\Models\Restriccion;
use App\Models\Rol;
use App\Models\Sede;
use App\Models\TipoDonacion;
use App\Models\Turno;
use App\Models\Usuario;
use App\Services\RecordatorioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * WU-3 surface: manual pre-donation reminder. Text is frozen at scheduling,
 * the record starts PENDIENTE/intento 1, the queued job is a no-op, estado
 * transitions advance, and no PII leaks into the body.
 */
class RecordatorioTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $staff;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::fake();
        $this->staff = $this->staff();
        Sanctum::actingAs($this->staff, ['*'], 'staff');
    }

    private function staff(): Usuario
    {
        $rol = Rol::firstOrCreate(['nombre' => 'ADMIN']);

        return Usuario::create([
            'username' => 'user_'.uniqid(),
            'password_hash' => Hash::make('1234'),
            'rol_id' => $rol->id,
        ]);
    }

    private function paciente(array $overrides = []): Paciente
    {
        $aptitud = Aptitud::firstOrCreate(['tipo' => 'APTO']);

        return Paciente::create(array_merge([
            'dni' => (string) fake()->unique()->numerify('########'),
            'nombre' => 'Ana',
            'apellido' => 'Gomez',
            'telefono' => fake()->phoneNumber(),
            'aptitud_id' => $aptitud->id,
        ], $overrides));
    }

    private function turno(?Paciente $paciente = null): Turno
    {
        $sede = Sede::firstOrCreate(['nombre' => 'Sede Central'], ['activa' => true]);
        $tipo = TipoDonacion::firstOrCreate(
            ['codigo' => 'SANGRE'],
            ['nombre' => 'SANGRE', 'duracion_minutos' => 30],
        );

        return Turno::create([
            'paciente_id' => ($paciente ?? $this->paciente())->id,
            'sede_id' => $sede->id,
            'tipo_id' => $tipo->id,
            'fecha' => '2026-06-01',
            'hora' => '10:00',
            'estado' => 'PENDIENTE',
        ]);
    }

    public function test_manual_trigger_creates_pendiente_record_and_queues_job(): void
    {
        $turno = $this->turno();

        $response = $this->postJson('/api/turnos/'.$turno->id.'/recordatorio');

        $response->assertStatus(201);

        $record = RecordatorioTurno::firstOrFail();
        $this->assertSame('WHATSAPP', $record->canal);
        $this->assertSame('PENDIENTE', $record->estado);
        $this->assertSame(1, $record->intento);
        $this->assertSame($turno->id, $record->turno_id);

        Queue::assertPushed(EnviarRecordatorioWhatsApp::class);
    }

    public function test_index_lists_records_for_a_turno(): void
    {
        $turno = $this->turno();

        $this->postJson('/api/turnos/'.$turno->id.'/recordatorio')->assertStatus(201);

        $response = $this->getJson('/api/turnos/'.$turno->id.'/recordatorios');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json());
    }

    public function test_content_is_frozen_at_scheduling(): void
    {
        $turno = $this->turno();

        $this->postJson('/api/turnos/'.$turno->id.'/recordatorio')->assertStatus(201);
        $original = RecordatorioTurno::firstOrFail()->contenido;

        // A later template change must not rewrite stored history.
        config(['recordatorio_predonacion.plantilla' => 'V2 :nombre']);
        app()->forgetInstance(RecordatorioService::class);

        $this->assertSame($original, RecordatorioTurno::firstOrFail()->contenido);
    }

    public function test_state_transitions_advance_and_increment_on_failure(): void
    {
        $turno = $this->turno();
        $this->postJson('/api/turnos/'.$turno->id.'/recordatorio')->assertStatus(201);

        $service = app(RecordatorioService::class);
        $record = RecordatorioTurno::firstOrFail();

        $service->markSent($record);
        $this->assertSame('ENVIADO', $record->fresh()->estado);

        $service->markFailed($record->fresh());
        $failed = $record->fresh();
        $this->assertSame('FALLIDO', $failed->estado);
        $this->assertSame(2, $failed->intento);
    }

    public function test_record_persists_without_a_worker_running(): void
    {
        $turno = $this->turno();

        $this->postJson('/api/turnos/'.$turno->id.'/recordatorio')->assertStatus(201);

        // Queue::fake means no worker executes; the record is still readable PENDIENTE.
        $this->assertSame(1, RecordatorioTurno::where('estado', 'PENDIENTE')->count());
    }

    public function test_no_pii_leaks_into_the_body(): void
    {
        $paciente = $this->paciente(['dni' => '30123456']);
        $turno = $this->turno($paciente);

        // Give the patient a deferral with a clinical reason.
        $motivo = \App\Models\Motivo::create(['nombre' => 'Hemoglobina baja']);
        Restriccion::create([
            'paciente_id' => $paciente->id,
            'motivo_id' => $motivo->id,
            'desde' => '2026-05-01',
            'hasta' => '2026-07-01',
        ]);

        $this->postJson('/api/turnos/'.$turno->id.'/recordatorio')->assertStatus(201);

        $contenido = RecordatorioTurno::firstOrFail()->contenido;

        $this->assertStringNotContainsString('30123456', $contenido);
        $this->assertStringNotContainsString('Hemoglobina', $contenido);
        $this->assertStringNotContainsString('Diferimiento', $contenido);
        $this->assertStringContainsString('Ana', $contenido);
    }

    public function test_no_outbound_http_is_made(): void
    {
        $turno = $this->turno();

        $this->postJson('/api/turnos/'.$turno->id.'/recordatorio')->assertStatus(201);

        Http::assertNothingSent();
    }

    public function test_reminder_routes_require_staff_auth(): void
    {
        $turno = $this->turno();
        app('auth')->forgetGuards();

        $this->postJson('/api/turnos/'.$turno->id.'/recordatorio')->assertStatus(401);
    }
}
