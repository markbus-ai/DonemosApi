<?php

namespace Tests\Feature;

use App\Models\Aptitud;
use App\Models\AutorizacionExtraordinaria;
use App\Models\Donacion;
use App\Models\Paciente;
use App\Models\Rol;
use App\Models\Sede;
use App\Models\TipoDonacion;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The audit trail of a forced operation names every rule it overrode in
 * words, for turnos and donaciones alike; never a raw rule code.
 */
class AutorizacionMotivoTest extends TestCase
{
    use RefreshDatabase;

    private Sede $sede;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-22 13:00:00');
        Queue::fake();
        $this->sede = Sede::firstOrCreate(['nombre' => 'Sede Central'], ['activa' => true]);
        $this->sede->update(['codigo_localidad' => '1']);

        Sanctum::actingAs(Usuario::create([
            'username' => 'staff_'.uniqid(),
            'password_hash' => Hash::make('1234'),
            'rol_id' => Rol::firstOrCreate(['nombre' => 'staff'])->id,
            'sede_id' => $this->sede->id,
        ]), ['*'], 'staff');
    }

    private function paciente(string $aptitud = 'APTO', string $sexo = 'F'): Paciente
    {
        return Paciente::create([
            'dni' => (string) fake()->unique()->numerify('########'),
            'nombre' => 'Ana',
            'apellido' => 'Gomez',
            'telefono' => '2254000000',
            'sexo' => $sexo,
            'aptitud_id' => Aptitud::firstOrCreate(['tipo' => $aptitud])->id,
        ]);
    }

    private function tipo(string $codigo): TipoDonacion
    {
        return TipoDonacion::firstOrCreate(['codigo' => $codigo], ['nombre' => $codigo]);
    }

    public function test_forced_donation_of_a_no_apto_donor_inside_the_interval_is_labelled(): void
    {
        $paciente = $this->paciente('NO_APTO');
        $plasma = $this->tipo('PLASMA');
        Donacion::create(['paciente_id' => $paciente->id, 'sede_id' => $this->sede->id, 'tipo_id' => $plasma->id, 'fecha' => '2026-10-15', 'numero_donacion' => '001-2026-000001']);

        $this->postJson('/api/donaciones', [
            'paciente_id' => $paciente->id,
            'tipo_id' => $plasma->id,
            'componentes' => [$plasma->id],
            'fecha' => '2026-10-22',
            'forzar' => true,
            'motivo' => 'Pedido del médico',
        ])->assertCreated();

        $this->assertSame(
            'Donante NO APTO + Incumplimiento de intervalo mínimo por sexo + Incumplimiento de intervalo mínimo — Pedido del médico',
            AutorizacionExtraordinaria::latest('id')->value('motivo'),
        );
    }

    public function test_forced_turno_of_a_no_apto_donor_is_labelled(): void
    {
        $paciente = $this->paciente('NO_APTO');
        $this->tipo('PLASMA');

        $this->postJson('/api/turnos', [
            'paciente_id' => $paciente->id,
            'fecha' => '2026-11-02',
            'hora' => '09:00',
            'forzar' => true,
            'motivo' => 'Pedido del médico',
        ])->assertCreated();

        $this->assertSame('Donante NO APTO — Pedido del médico', AutorizacionExtraordinaria::latest('id')->value('motivo'));
    }
}
