<?php

namespace App\Services;

use App\Models\Paciente;
use App\Models\Restriccion;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class RestriccionService
{
    /**
     * Append a deferral. Any prior active row is closed (never deleted) so the
     * patient keeps at most one active deferral while history is preserved.
     */
    public function create(Paciente $paciente, array $data): Restriccion
    {
        return DB::transaction(function () use ($paciente, $data) {
            $desde = Carbon::parse($data['desde'])->startOfDay()->toDateString();

            $paciente->restricciones()
                ->vigente(now()->toDateString())
                ->get()
                ->each(fn (Restriccion $restriccion) => $restriccion->update([
                    'hasta' => $desde,
                    'permanente' => false,
                ]));

            $hasta = $data['hasta'] ?? null;

            return $paciente->restricciones()->create([
                'motivo_id' => $data['motivo_id'],
                'desde' => $desde,
                'hasta' => $hasta,
                'permanente' => $data['permanente'] ?? ($hasta === null),
            ]);
        });
    }

    /**
     * Close an active deferral today instead of deleting it.
     */
    public function close(Restriccion $restriccion): void
    {
        $restriccion->update([
            'hasta' => now()->toDateString(),
            'permanente' => false,
        ]);
    }
}
