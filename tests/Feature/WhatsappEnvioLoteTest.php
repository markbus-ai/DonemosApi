<?php

namespace Tests\Feature;

use App\Jobs\EnviarLoteWhatsApp;
use App\Models\Aptitud;
use App\Models\Paciente;
use App\Models\RecordatorioTurno;
use App\Models\Rol;
use App\Models\Sede;
use App\Models\Turno;
use App\Models\Usuario;
use App\Services\RecordatorioService;
use App\Services\Whatsapp\WhatsappSender;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/**
 * Batch delivery never double-messages: rows are claimed (ENVIANDO) before the
 * sender runs, a crashing sender marks them FALLIDO, and nothing already
 * claimed or sent is ever picked again.
 */
class WhatsappEnvioLoteTest extends TestCase
{
    use RefreshDatabase;

    private Sede $sede;

    /** @var array<int, array<int, array{numero: string, mensaje: string}>> */
    private array $lotes = [];

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

    private function turno(string $fecha = '2026-10-23', string $hora = '09:00'): Turno
    {
        $paciente = Paciente::create([
            'dni' => (string) fake()->unique()->numerify('########'),
            'nombre' => 'Ana',
            'apellido' => 'Gomez',
            'telefono' => '2254 123456',
            'aptitud_id' => Aptitud::firstOrCreate(['tipo' => 'APTO'])->id,
        ]);

        return Turno::create(['paciente_id' => $paciente->id, 'sede_id' => $this->sede->id, 'fecha' => $fecha, 'hora' => $hora, 'estado' => 'PENDIENTE']);
    }

    private function recordatorio(Turno $turno, string $tipo = 'RECORDATORIO', string $estado = 'PENDIENTE'): RecordatorioTurno
    {
        return $turno->recordatorios()->create(['canal' => 'WHATSAPP', 'tipo' => $tipo, 'estado' => $estado, 'contenido' => 'Hola Ana', 'intento' => 1]);
    }

    /**
     * Sender double: records every batch and the row states it saw, then
     * answers with $respuesta (a callable gets the batch) or throws.
     */
    private function sender(callable|\Throwable|null $respuesta = null): WhatsappSender
    {
        $test = $this;

        return new class($test, $respuesta) implements WhatsappSender
        {
            /** @var array<int, array<int, string>> */
            public array $estadosVistos = [];

            public function __construct(private WhatsappEnvioLoteTest $test, private mixed $respuesta) {}

            public function sendLote(array $mensajes): array
            {
                $this->test->registrarLote($mensajes);
                $this->estadosVistos[] = RecordatorioTurno::whereIn('id', array_keys($mensajes))->pluck('estado', 'id')->all();

                if ($this->respuesta instanceof \Throwable) {
                    throw $this->respuesta;
                }

                return is_callable($this->respuesta)
                    ? ($this->respuesta)($mensajes)
                    : array_fill_keys(array_keys($mensajes), null);
            }
        };
    }

    public function registrarLote(array $mensajes): void
    {
        $this->lotes[] = $mensajes;
    }

    private function enviarLote(WhatsappSender $sender): void
    {
        (new EnviarLoteWhatsApp)->handle($sender, app(RecordatorioService::class));
    }

    public function test_rows_are_claimed_as_enviando_while_the_sender_runs(): void
    {
        $record = $this->recordatorio($this->turno());
        $sender = $this->sender();

        $this->enviarLote($sender);

        $this->assertSame([[$record->id => 'ENVIANDO']], $sender->estadosVistos);
        $this->assertSame('ENVIADO', $record->fresh()->estado);
    }

    public function test_a_crashing_sender_marks_the_batch_failed_and_never_resends(): void
    {
        $records = [$this->recordatorio($this->turno()), $this->recordatorio($this->turno('2026-10-23', '10:00'))];

        $this->enviarLote($this->sender(new RuntimeException('Timeout de WhatsPlay')));
        $this->enviarLote($this->sender());

        $this->assertCount(1, $this->lotes);
        foreach ($records as $record) {
            $record->refresh();
            $this->assertSame('FALLIDO', $record->estado);
            $this->assertStringContainsString('Timeout de WhatsPlay', $record->error);
            $this->assertSame(2, $record->intento);
        }
    }

    public function test_claimed_and_sent_rows_are_never_picked_again(): void
    {
        $turno = $this->turno();
        $enviando = $this->recordatorio($turno, 'CONFIRMACION', 'ENVIANDO');
        $enviado = $this->recordatorio($turno, 'RECORDATORIO', 'ENVIADO');
        $pendiente = $this->recordatorio($this->turno('2026-10-23', '10:00'));

        $this->enviarLote($this->sender());

        $this->assertSame([[$pendiente->id]], array_map('array_keys', $this->lotes));
        $this->assertSame('ENVIANDO', $enviando->fresh()->estado);
        $this->assertSame('ENVIADO', $enviado->fresh()->estado);
    }

    public function test_a_partial_batch_rerun_only_sends_what_was_left(): void
    {
        config(['whatsapp.lote_maximo' => 2]);
        Queue::fake();
        $records = collect(range(8, 10))->map(fn ($h) => $this->recordatorio($this->turno('2026-10-23', sprintf('%02d:00', $h))));

        $this->enviarLote($this->sender());
        Queue::assertPushed(EnviarLoteWhatsApp::class, 1);
        $this->enviarLote($this->sender());

        $this->assertSame([[$records[0]->id, $records[1]->id], [$records[2]->id]], array_map('array_keys', $this->lotes));
        $this->assertSame(['ENVIADO'], RecordatorioTurno::pluck('estado')->unique()->values()->all());
    }

    public function test_ids_missing_from_the_sender_answer_are_failed(): void
    {
        $uno = $this->recordatorio($this->turno());
        $dos = $this->recordatorio($this->turno('2026-10-23', '10:00'));

        $this->enviarLote($this->sender(fn (array $mensajes) => [$uno->id => null]));

        $this->assertSame('ENVIADO', $uno->fresh()->estado);
        $this->assertSame('FALLIDO', $dos->fresh()->estado);
        $this->assertNotEmpty($dos->fresh()->error);
    }

    public function test_a_job_that_dies_mid_send_fails_its_claimed_rows(): void
    {
        $record = $this->recordatorio($this->turno(), 'RECORDATORIO', 'ENVIANDO');

        (new EnviarLoteWhatsApp)->failed(new TimeoutExceededException('timeout'));

        $this->assertSame('FALLIDO', $record->fresh()->estado);
        $this->assertNotEmpty($record->fresh()->error);
    }

    public function test_a_job_dropped_before_running_leaves_another_batch_alone(): void
    {
        $record = $this->recordatorio($this->turno(), 'RECORDATORIO', 'ENVIANDO');

        (new EnviarLoteWhatsApp)->failed(new MaxAttemptsExceededException('overlapping'));

        $this->assertSame('ENVIANDO', $record->fresh()->estado);
    }

    public function test_programar_never_reschedules_a_claimed_or_sent_message(): void
    {
        Queue::fake();
        $turno = $this->turno();
        $this->recordatorio($turno, 'RECORDATORIO', 'ENVIANDO');
        $this->recordatorio($turno, 'CONFIRMACION', 'ENVIADO');

        $this->assertNull(app(RecordatorioService::class)->programar($turno, 'RECORDATORIO'));
        $this->assertNull(app(RecordatorioService::class)->programar($turno, 'CONFIRMACION'));
        $this->assertSame(2, RecordatorioTurno::count());
    }

    public function test_cancelling_voids_pending_messages_before_the_notice(): void
    {
        Queue::fake();
        $turno = $this->turno('2026-11-03');
        $confirmacion = $this->recordatorio($turno, 'CONFIRMACION');
        $recordatorio = $this->recordatorio($turno, 'RECORDATORIO');
        $enviado = $this->recordatorio($turno, 'RECORDATORIO', 'ENVIADO');

        $this->patchJson("/api/turnos/{$turno->id}", ['estado' => 'CANCELADO'])->assertOk();

        foreach ([$confirmacion, $recordatorio] as $record) {
            $this->assertSame('FALLIDO', $record->fresh()->estado);
            $this->assertSame('Turno cancelado antes del envío', $record->fresh()->error);
        }
        $this->assertSame('ENVIADO', $enviado->fresh()->estado);
        $this->assertSame('PENDIENTE', RecordatorioTurno::where('tipo', 'CANCELACION')->value('estado'));
    }

    public function test_the_database_accepts_enviando_and_rejects_unknown_states(): void
    {
        $turno = $this->turno();

        $this->assertSame('ENVIANDO', $this->recordatorio($turno, 'RECORDATORIO', 'ENVIANDO')->fresh()->estado);

        $this->expectException(QueryException::class);
        DB::table('recordatorios_turno')->insert(['turno_id' => $turno->id, 'canal' => 'WHATSAPP', 'tipo' => 'RECORDATORIO', 'estado' => 'PERDIDO', 'contenido' => 'x', 'intento' => 1]);
    }

    public function test_programar_does_not_insert_while_another_caller_holds_the_slot(): void
    {
        Queue::fake();
        Sleep::fake(syncWithCarbon: true);
        $turno = $this->turno();
        $otro = Cache::lock("recordatorio:{$turno->id}:CONFIRMACION", 10);
        $this->assertTrue($otro->get());

        $this->assertNull(app(RecordatorioService::class)->programar($turno, 'CONFIRMACION'));
        $this->assertSame(0, RecordatorioTurno::count());

        $otro->release();
        $this->assertNotNull(app(RecordatorioService::class)->programar($turno, 'CONFIRMACION'));
        $this->assertSame(1, RecordatorioTurno::count());
    }
}
