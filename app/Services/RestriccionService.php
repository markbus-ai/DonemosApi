<?php

namespace App\Services;

use App\Models\Paciente;
use App\Models\Restriccion;
use App\Support\Hoy;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

            $activas = $paciente->restricciones()->vigente(Hoy::fecha())->get();
            self::assertNotBeforeActive($activas, $desde);

            $activas->each(fn (Restriccion $restriccion) => $restriccion->update([
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
     * A new deferral may only start on or after the active one: closing an
     * active block at an earlier date would silently lift it.
     *
     * @param  Collection<int, Restriccion>  $activas
     */
    public static function assertNotBeforeActive($activas, string $desde): void
    {
        $inicioActivo = $activas->max(fn (Restriccion $r) => Carbon::parse($r->desde)->toDateString());

        if ($inicioActivo !== null && $desde < $inicioActivo) {
            throw ValidationException::withMessages([
                'desde' => sprintf('Ya hay un diferimiento activo desde %s; la nueva fecha no puede ser anterior.', Carbon::parse($inicioActivo)->format('d/m/Y')),
            ]);
        }
    }

    /**
     * Close an active deferral today instead of deleting it.
     */
    public function close(Restriccion $restriccion): void
    {
        $restriccion->update([
            'hasta' => Hoy::fecha(),
            'permanente' => false,
        ]);
    }
}
