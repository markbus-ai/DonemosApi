<?php

namespace App\Services;

use App\Models\AutorizacionExtraordinaria;
use App\Models\Turno;
use App\Rules\TurnoRules;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TurnoService
{
    public function __construct(
        private TurnoRules $turnoRules
    ) {}

    public function list(array $filters = []): Collection
    {
        return Turno::query()
            ->when(isset($filters['paciente_id']), fn ($q) => $q->where('paciente_id', $filters['paciente_id']))
            ->when(isset($filters['fecha']), fn ($q) => $q->whereDate('fecha', $filters['fecha']))
            ->orderBy('fecha')
            ->orderBy('hora')
            ->get();
    }

    /**
     * Crea un turno. Si viola reglas (vía TurnoRules: intervalos por DonationRules + turnos PENDIENTE)
     * y no viene forzar=true, retorna array con warning para que el Controller responda 409.
     * Si viene forzar=true, crea turno + autorización en transacción.
     *
     * Delega validación a TurnoRules (que inyecta DonationRules internamente).
     *
     * Soporte opcional tipo_id: si viene, evalúa solo ese tipo; si no, evalúa PLASMA y PLAQUETAS y mergea warnings.
     *
     * @return Turno|array{warning: bool, code: string, message: string, warnings?: array}
     */
    public function create(array $data): Turno|array
    {
        $warnings = $this->resolveWarnings($data);

        if (!empty($warnings) && empty($data['forzar'])) {
            return [
                'warning' => true,
                'code' => $warnings[0]['code'],
                'message' => $warnings[0]['message'],
                'warnings' => $warnings,
            ];
        }

        return DB::transaction(function () use ($data, $warnings) {
            $payload = [
                'paciente_id' => $data['paciente_id'],
                'fecha' => $data['fecha'],
                'hora' => $data['hora'],
                'estado' => 'PENDIENTE',
            ];

            // Soporte opcional tipo_id en DB si la columna existe (compatibilidad sin migración)
            if (isset($data['tipo_id']) && Schema::hasColumn('turnos', 'tipo_id')) {
                $payload['tipo_id'] = $data['tipo_id'];
            }

            $turno = Turno::create($payload);

            // Si había violación y el personal forzó, registrar autorización
            if (!empty($warnings) && !empty($data['forzar'])) {
                $motivo = $this->buildMotivo($warnings);
                $usuarioId = $data['usuario_id'] ?? auth()->id() ?? \App\Models\Usuario::first()?->id;

                if ($usuarioId) {
                    AutorizacionExtraordinaria::create([
                        'paciente_id' => $data['paciente_id'],
                        'usuario_id' => $usuarioId,
                        'motivo' => $motivo,
                        'fecha' => now(),
                    ]);
                }
            }

            return $turno;
        });
    }

    public function update(Turno $turno, array $data): Turno
    {
        $turno->update($data);

        return $turno->fresh();
    }

    /**
     * Resuelve warnings via TurnoRules (que delega internamente a DonationRules + turnos PENDIENTE).
     *
     * @return array<int, array{code: string, message: string}>
     */
    private function resolveWarnings(array $data): array
    {
        $result = $this->turnoRules->check(
            $data['paciente_id'],
            $data['fecha'],
            $data['hora'],
            $data['tipo_id'] ?? null
        );

        return $result['warnings'] ?? [];
    }

    private function buildMotivo(array $warnings): string
    {
        $codes = array_column($warnings, 'code');

        if (empty($codes)) {
            return 'Autorización extraordinaria';
        }

        $map = [
            'INTERVALO_MINIMO' => 'Incumplimiento de intervalo mínimo',
            'LIMITE_ANUAL' => 'Supera límite anual',
            'LIMITE_PERIODO' => 'Supera límite del período',
            'WARNING_INTERVALO' => 'Incumplimiento de intervalo mínimo',
            'WARNING_LIMITE_ANUAL' => 'Supera límite anual',
            'WARNING_LIMITE_PERIODO' => 'Supera límite del período',
            'TURNO_PENDIENTE_EXISTENTE' => 'Paciente ya tiene turno pendiente',
        ];

        $motivos = array_map(fn ($c) => $map[$c] ?? $c, $codes);

        return implode(' + ', $motivos);
    }
}
