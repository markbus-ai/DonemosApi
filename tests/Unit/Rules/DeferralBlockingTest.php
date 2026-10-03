<?php

namespace Tests\Unit\Rules;

use App\Models\Aptitud;
use App\Models\Motivo;
use App\Models\Paciente;
use App\Models\Restriccion;
use App\Models\TipoDonacion;
use App\Rules\DonationRules;
use App\Rules\TurnoRules;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An active deferral blocks donation and turno eligibility network-wide,
 * independent of donation type and of the donation-type catalog.
 */
class DeferralBlockingTest extends TestCase
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

    private function tipo(string $codigo): TipoDonacion
    {
        return TipoDonacion::firstOrCreate(['codigo' => $codigo], ['nombre' => $codigo]);
    }

    private function activarDiferimiento(Paciente $paciente, array $overrides = []): Restriccion
    {
        $motivo = Motivo::create(['nombre' => fake()->unique()->word()]);

        return Restriccion::create(array_merge([
            'paciente_id' => $paciente->id,
            'motivo_id' => $motivo->id,
            'desde' => '2026-01-01',
            'hasta' => null,
            'permanente' => true,
        ], $overrides));
    }

    private function codes(array $result): array
    {
        return array_column($result['warnings'], 'code');
    }

    public function test_whole_blood_donation_blocked(): void
    {
        $paciente = $this->paciente();
        $this->activarDiferimiento($paciente);

        $result = (new DonationRules)->check($paciente, $this->tipo('SANGRE'), now()->toDateString());

        $this->assertFalse($result['allowed']);
        $this->assertContains('BLOQUEO_DIFERIMIENTO', $this->codes($result));
    }

    public function test_apheresis_donation_blocked_for_plaquetas_and_plasma(): void
    {
        $paciente = $this->paciente();
        $this->activarDiferimiento($paciente);

        foreach (['PLAQUETAS', 'PLASMA'] as $nombre) {
            $result = (new DonationRules)->check($paciente, $this->tipo($nombre), now()->toDateString());

            $this->assertFalse($result['allowed'], "{$nombre} should be blocked");
            $this->assertContains('BLOQUEO_DIFERIMIENTO', $this->codes($result), "{$nombre} should emit the block code");
        }
    }

    public function test_no_active_deferral_allows_donation(): void
    {
        $paciente = $this->paciente();
        $this->activarDiferimiento($paciente, ['desde' => '2026-01-01', 'hasta' => '2026-02-01', 'permanente' => false]);
        $this->activarDiferimiento($paciente, ['desde' => '2026-03-01', 'hasta' => '2026-04-01', 'permanente' => false]);

        $result = (new DonationRules)->check($paciente, $this->tipo('SANGRE'), now()->toDateString());

        $this->assertTrue($result['allowed']);
        $this->assertNotContains('BLOQUEO_DIFERIMIENTO', $this->codes($result));
    }

    public function test_motivo_plazo_null_alone_never_blocks(): void
    {
        $paciente = $this->paciente();
        Motivo::create(['nombre' => 'referencia', 'codigo' => 'REF', 'plazo_meses' => null]);

        $result = (new DonationRules)->check($paciente, $this->tipo('SANGRE'), now()->toDateString());

        $this->assertTrue($result['allowed']);
        $this->assertNotContains('BLOQUEO_DIFERIMIENTO', $this->codes($result));
    }

    public function test_return_day_allows_donation_but_day_before_blocks(): void
    {
        $paciente = $this->paciente();
        $retorno = Carbon::tomorrow()->toDateString();
        $this->activarDiferimiento($paciente, [
            'desde' => Carbon::today()->toDateString(),
            'hasta' => $retorno,
            'permanente' => false,
        ]);

        $blocked = (new DonationRules)->check($paciente, $this->tipo('SANGRE'), Carbon::today()->toDateString());
        $this->assertFalse($blocked['allowed']);
        $this->assertContains('BLOQUEO_DIFERIMIENTO', $this->codes($blocked));

        $allowed = (new DonationRules)->check($paciente, $this->tipo('SANGRE'), $retorno);
        $this->assertTrue($allowed['allowed']);
    }

    public function test_turno_block_is_type_independent_with_empty_catalog(): void
    {
        $paciente = $this->paciente();
        $this->activarDiferimiento($paciente);

        $rules = new TurnoRules(new DonationRules);
        $result = $rules->check($paciente, Carbon::tomorrow()->toDateString(), '10:00', null);

        $this->assertFalse($result['allowed']);
        $this->assertContains('BLOQUEO_DIFERIMIENTO', $this->codes($result));
    }

    public function test_turno_emits_deferral_code_exactly_once(): void
    {
        $paciente = $this->paciente();
        $this->activarDiferimiento($paciente);
        $tipo = $this->tipo('PLAQUETAS');

        $rules = new TurnoRules(new DonationRules);
        $result = $rules->check($paciente, Carbon::tomorrow()->toDateString(), '10:00', $tipo->id);

        $deferralCodes = array_filter($this->codes($result), fn ($code) => $code === 'BLOQUEO_DIFERIMIENTO');
        $this->assertCount(1, $deferralCodes);
    }
}
