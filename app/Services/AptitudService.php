<?php

namespace App\Services;

use App\Models\Aptitud;
use App\Models\Paciente;
use App\Models\Restriccion;
use App\Support\Hoy;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AptitudService
{
    public function update(Paciente $paciente, array $data): Paciente
    {
        return DB::transaction(function () use ($paciente, $data) {
            $aptitud = Aptitud::where('tipo', $data['tipo'])->firstOrFail();
            $hoy = Hoy::fecha();

            if ($data['tipo'] === 'APTO') {
                $paciente->observaciones()->delete();
                $this->closeActiveDeferrals($paciente, $hoy);
            } elseif ($data['tipo'] === 'APTO_OBSERVACION') {
                $this->closeActiveDeferrals($paciente, $hoy);
            } elseif ($data['tipo'] === 'NO_APTO') {
                RestriccionService::assertNotBeforeActive(
                    $paciente->restricciones()->vigente($hoy)->get(),
                    Carbon::parse($data['desde'])->toDateString(),
                );
                $paciente->observaciones()->delete();
                $this->closeActiveDeferrals($paciente, $data['desde']);
            }

            $paciente->update(['aptitud_id' => $aptitud->id]);

            if ($data['tipo'] === 'APTO_OBSERVACION') {
                $paciente->observaciones()->create([
                    'motivo_id' => $data['motivo_id'],
                    'desde' => $data['desde'],
                    'hasta' => $data['hasta'] ?? null,
                ]);
            }

            if ($data['tipo'] === 'NO_APTO') {
                $hasta = $data['hasta'] ?? null;

                $paciente->restricciones()->create([
                    'motivo_id' => $data['motivo_id'],
                    'desde' => $data['desde'],
                    'hasta' => $hasta,
                    'permanente' => $data['permanente'] ?? ($hasta === null),
                ]);
            }

            return $paciente->fresh()->load('aptitud');
        });
    }

    /**
     * Close active deferrals at the given date, preserving history.
     *
     * APTO / APTO_OBSERVACION close them today; NO_APTO closes them at the
     * new deferral's start date before inserting the replacement row.
     */
    private function closeActiveDeferrals(Paciente $paciente, string $hasta): void
    {
        $paciente->restricciones()
            ->vigente(Hoy::fecha())
            ->get()
            ->each(fn (Restriccion $restriccion) => $restriccion->update([
                'hasta' => $hasta,
                'permanente' => false,
            ]));
    }
}
