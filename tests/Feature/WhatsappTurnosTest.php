<?php

namespace Tests\Feature;

use App\Jobs\EnviarRecordatorioWhatsApp;
use App\Models\Aptitud;
use App\Models\Paciente;
use App\Models\RecordatorioTurno;
use App\Models\Rol;
use App\Models\Sede;
use App\Models\Turno;
use App\Models\Usuario;
use App\Services\RecordatorioService;
use App\Services\Whatsapp\WhatsappSender;
use App\Support\TelefonoAR;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Automatic turno messages over WhatsApp (WhatsPlay): confirmation on booking,
 * reminder the day before, cancellation notice, with a test-number safety net.
 */
class WhatsappTurnosTest extends TestCase
{
    use RefreshDatabase;

    private Sede $sede;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-22 13:00:00');
        config(['app.business_timezone' => 'America/Argentina/Buenos_Aires', 'whatsapp.driver' => 'log', 'whatsapp.numero_prueba' => null]);

        $this->sede = Sede::firstOrCreate(['nombre' => 'Sede Central'], ['activa' => true]);
        Sanctum::actingAs(Usuario::create([
            'username' => 'staff_'.uniqid(),
            'password_hash' => Hash::make('1234'),
            'rol_id' => Rol::firstOrCreate(['nombre' => 'staff'])->id,
            'sede_id' => $this->sede->id,
        ]), ['*'], 'staff');
    }

    private function paciente(string $telefono = '2254 123456'): Paciente
    {
        return Paciente::create([
            'dni' => (string) fake()->unique()->numerify('########'),
            'nombre' => 'Ana',
            'apellido' => 'Gomez',
            'telefono' => $telefono,
            'aptitud_id' => Aptitud::firstOrCreate(['tipo' => 'APTO'])->id,
        ]);
    }

    private function turno(string $fecha = '2026-10-23', ?Paciente $paciente = null): Turno
    {
        return Turno::create(['paciente_id' => ($paciente ?? $this->paciente())->id, 'sede_id' => $this->sede->id, 'fecha' => $fecha, 'hora' => '09:00', 'estado' => 'PENDIENTE']);
    }

    private function recordatorio(Turno $turno, string $tipo = 'RECORDATORIO'): RecordatorioTurno
    {
        return $turno->recordatorios()->create(['canal' => 'WHATSAPP', 'tipo' => $tipo, 'estado' => 'PENDIENTE', 'contenido' => 'Hola Ana', 'intento' => 1]);
    }

    public function test_argentine_phones_are_normalised_for_whatsapp(): void
    {
        $this->assertSame('5492254123456', TelefonoAR::paraWhatsapp('+54 9 2254 123456'));
        $this->assertSame('5492254123456', TelefonoAR::paraWhatsapp('2254123456'));
        $this->assertSame('5492254123456', TelefonoAR::paraWhatsapp('54 2254 123456'));
        $this->assertSame('5492254123456', TelefonoAR::paraWhatsapp('02254 15 123456'));
        $this->assertSame('5491112345678', TelefonoAR::paraWhatsapp('011 15 1234-5678'));
        $this->assertNull(TelefonoAR::paraWhatsapp('123'));
        $this->assertNull(TelefonoAR::paraWhatsapp(null));
    }

    public function test_booking_a_turno_queues_a_confirmation(): void
    {
        Queue::fake();

        $this->postJson('/api/turnos', ['paciente_id' => $this->paciente()->id, 'fecha' => '2026-11-02', 'hora' => '09:00'])->assertCreated();

        $this->assertSame(['CONFIRMACION'], RecordatorioTurno::pluck('tipo')->all());
        Queue::assertPushed(EnviarRecordatorioWhatsApp::class);
    }

    public function test_cancelling_a_turno_queues_a_cancellation_notice(): void
    {
        Queue::fake();
        $turno = $this->turno('2026-11-03');

        $this->patchJson("/api/turnos/{$turno->id}", ['estado' => 'CANCELADO'])->assertOk();

        $this->assertSame('CANCELACION', RecordatorioTurno::where('turno_id', $turno->id)->value('tipo'));
        $this->assertStringContainsString('cancelado', RecordatorioTurno::where('turno_id', $turno->id)->value('contenido'));
    }

    public function test_tomorrows_pending_turnos_get_one_reminder_each(): void
    {
        Queue::fake();
        $manana = $this->turno('2026-10-23');
        $this->turno('2026-10-24');
        Turno::create(['paciente_id' => $this->paciente()->id, 'sede_id' => $this->sede->id, 'fecha' => '2026-10-23', 'hora' => '10:00', 'estado' => 'CANCELADO']);

        $this->artisan('recordatorios:enviar-manana')->assertSuccessful();
        $this->artisan('recordatorios:enviar-manana')->assertSuccessful();

        $this->assertSame([$manana->id], RecordatorioTurno::where('tipo', 'RECORDATORIO')->pluck('turno_id')->all());
    }

    public function test_reminders_are_scheduled_daily(): void
    {
        $commands = collect(app(Schedule::class)->events())->map(fn ($e) => $e->command)->implode(' ');

        $this->assertStringContainsString('recordatorios:enviar-manana', $commands);
    }

    public function test_log_driver_marks_the_message_sent_without_leaving_the_machine(): void
    {
        Process::fake();
        $record = $this->recordatorio($this->turno());

        (new EnviarRecordatorioWhatsApp($record->id))->handle(app(WhatsappSender::class), app(RecordatorioService::class));

        $record->refresh();
        $this->assertSame('ENVIADO', $record->estado);
        $this->assertSame('5492254123456', $record->destino);
        Process::assertNothingRan();
    }

    public function test_whatsplay_driver_redirects_to_the_test_number_outside_production(): void
    {
        config(['whatsapp.driver' => 'whatsplay', 'whatsapp.numero_prueba' => '5492234480402', 'whatsapp.whatsplay.dir' => '/opt/whatsapp-skill']);
        Process::fake(['*' => Process::result(output: "RESULTADO OK\n")]);
        $record = $this->recordatorio($this->turno());

        (new EnviarRecordatorioWhatsApp($record->id))->handle(app(WhatsappSender::class), app(RecordatorioService::class));

        $record->refresh();
        $this->assertSame('ENVIADO', $record->estado);
        $this->assertSame('5492234480402', $record->destino);
        Process::assertRan(fn ($process) => in_array('--to', $process->command, true)
            && in_array('5492234480402', $process->command, true)
            && in_array('enviar', $process->command, true)
            && $process->path === '/opt/whatsapp-skill');
    }

    public function test_whatsplay_without_a_test_number_never_sends_outside_production(): void
    {
        config(['whatsapp.driver' => 'whatsplay']);
        Process::fake();
        $record = $this->recordatorio($this->turno());

        (new EnviarRecordatorioWhatsApp($record->id))->handle(app(WhatsappSender::class), app(RecordatorioService::class));

        $this->assertSame('FALLIDO', $record->fresh()->estado);
        $this->assertStringContainsString('WHATSAPP_NUMERO_PRUEBA', $record->fresh()->error);
        Process::assertNothingRan();
    }

    public function test_a_failed_send_is_recorded_with_its_reason(): void
    {
        config(['whatsapp.driver' => 'whatsplay', 'whatsapp.numero_prueba' => '5492234480402']);
        Process::fake(['*' => Process::result(output: "RESULTADO ERROR send_message devolvió False\n", exitCode: 1)]);
        $record = $this->recordatorio($this->turno());

        (new EnviarRecordatorioWhatsApp($record->id))->handle(app(WhatsappSender::class), app(RecordatorioService::class));

        $record->refresh();
        $this->assertSame('FALLIDO', $record->estado);
        $this->assertSame(2, $record->intento);
        $this->assertNotEmpty($record->error);
    }

    public function test_an_invalid_phone_is_never_sent(): void
    {
        Process::fake();
        $record = $this->recordatorio($this->turno('2026-10-23', $this->paciente('123')));

        (new EnviarRecordatorioWhatsApp($record->id))->handle(app(WhatsappSender::class), app(RecordatorioService::class));

        $this->assertSame('FALLIDO', $record->fresh()->estado);
        $this->assertStringContainsString('Teléfono', $record->fresh()->error);
    }
}
