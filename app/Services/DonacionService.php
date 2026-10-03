<?php

namespace App\Services;

use App\Models\AutorizacionExtraordinaria;
use App\Models\Donacion;
use App\Models\Paciente;
use App\Models\TipoDonacion;
use App\Rules\DonationRules;
use Illuminate\Support\Facades\DB;

class DonacionService
{
    public function __construct(
        private DonationRules $donationRules
    ) {}

    /**
     * @return array{donacion: ?Donacion, warnings: array, allowed: bool}
     */
    public function create(array $data): array
    {
        $paciente = Paciente::findOrFail($data['paciente_id']);
        $tipo = TipoDonacion::findOrFail($data['tipo_id']);
        $fecha = $data['fecha'] ?? now()->toDateString();

        $result = $this->donationRules->check($paciente, $tipo, $fecha);
        $warnings = $result['warnings'];
        $allowed = $result['allowed'];

        // Si hay warnings y no se fuerza, no crear nada. Devolver warnings para 409.
        if (!$allowed && empty($data['forzar'])) {
            return [
                'donacion' => null,
                'warnings' => $warnings,
                'allowed' => false,
            ];
        }

        // Si hay warnings y se fuerza, crear autorización + donación en transacción
        return DB::transaction(function () use ($paciente, $tipo, $fecha, $warnings, $allowed, $data) {
            if (!$allowed && !empty($data['forzar'])) {
                // Motivo autogenerado a partir del code, no viene del cliente
                $motivo = $this->buildMotivo($warnings);

                // TODO: usuario_id debe venir del contexto auth. Por ahora fallback a primer usuario o null.
                $usuarioId = $data['usuario_id'] ?? auth()->id() ?? \App\Models\Usuario::first()?->id;

                if ($usuarioId) {
                    AutorizacionExtraordinaria::create([
                        'paciente_id' => $paciente->id,
                        'usuario_id' => $usuarioId,
                        'motivo' => $motivo,
                        'fecha' => now(),
                    ]);
                }
            }

            $donacion = Donacion::create([
                'paciente_id' => $paciente->id,
                'tipo_id' => $tipo->id,
                'fecha' => $fecha,
            ]);

            return [
                'donacion' => $donacion,
                'warnings' => $warnings,
                'allowed' => true,
            ];
        });
    }

    public function list(int $pacienteId): \Illuminate\Database\Eloquent\Collection
    {
        return Donacion::where('paciente_id', $pacienteId)->orderByDesc('fecha')->get();
    }

    private function buildMotivo(array $warnings): string
    {
        // Genera motivo a partir del code, no del message que se muestra al usuario
        $codes = array_column($warnings, 'code');

        if (empty($codes)) {
            return 'Autorización extraordinaria';
        }

        $map = [
            'INTERVALO_MINIMO' => 'Incumplimiento de intervalo mínimo',
            'LIMITE_ANUAL' => 'Supera límite anual',
            'LIMITE_PERIODO' => 'Supera límite del período',
        ];

        $motivos = array_map(fn ($c) => $map[$c] ?? $c, $codes);

        return implode(' + ', $motivos);
    }
}
