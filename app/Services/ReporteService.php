<?php

namespace App\Services;

use App\Models\Donacion;
use App\Models\Turno;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Read-only aggregate dashboard for the desktop app. Every metric is a grouped
 * aggregate (no per-row queries). The optional `desde` / `hasta` bounds apply to
 * `donaciones.fecha` for donation metrics and to `turnos.fecha` for turnos.
 */
class ReporteService
{
    /**
     * @param  array{desde?: mixed, hasta?: mixed}  $filters
     * @return array{
     *     total_donaciones: int,
     *     donaciones_descartadas: int,
     *     por_sexo: array{M: int, F: int, sin_dato: int},
     *     por_producto: array<int, array{codigo: string, total: int}>,
     *     turnos_por_estado: array<string, int>
     * }
     */
    public function resumen(array $filters = []): array
    {
        return [
            'total_donaciones' => $this->donacionesBase($filters)->count(),
            'donaciones_descartadas' => $this->donacionesDescartadas($filters),
            'por_sexo' => $this->porSexo($filters),
            'por_producto' => $this->porProducto($filters),
            'turnos_por_estado' => $this->turnosPorEstado($filters),
        ];
    }

    /**
     * Donation query with the shared date bounds already applied.
     */
    private function donacionesBase(array $filters): Builder
    {
        return Donacion::query()
            ->when(filled($filters['desde'] ?? null), fn (Builder $query) => $query->where('fecha', '>=', $filters['desde']))
            ->when(filled($filters['hasta'] ?? null), fn (Builder $query) => $query->where('fecha', '<=', $filters['hasta']));
    }

    /**
     * Donations with a self-exclusion OR with at least one component and every
     * one of them discarded; matches Donacion::getDescartadaAttribute().
     */
    private function donacionesDescartadas(array $filters): int
    {
        return $this->donacionesBase($filters)
            ->where(function (Builder $query) {
                $query->whereHas('autoexclusion')
                    ->orWhere(function (Builder $query) {
                        $query->whereHas('componentes')
                            ->whereDoesntHave('componentes', fn (Builder $query) => $query->where('descartado', false));
                    });
            })
            ->count();
    }

    /**
     * @return array{M: int, F: int, sin_dato: int}
     */
    private function porSexo(array $filters): array
    {
        $counts = $this->donacionesBase($filters)
            ->join('pacientes', 'pacientes.id', '=', 'donaciones.paciente_id')
            ->select('pacientes.sexo', DB::raw('count(*) as total'))
            ->groupBy('pacientes.sexo')
            ->pluck('total', 'sexo');

        $buckets = ['M' => 0, 'F' => 0, 'sin_dato' => 0];

        foreach ($counts as $sexo => $total) {
            $key = in_array($sexo, ['M', 'F'], true) ? $sexo : 'sin_dato';
            $buckets[$key] += (int) $total;
        }

        return $buckets;
    }

    /**
     * Component rows grouped by catalog code; quantity equals the row count.
     *
     * @return array<int, array{codigo: string, total: int}>
     */
    private function porProducto(array $filters): array
    {
        return DB::table('componentes_donacion')
            ->join('donaciones', 'donaciones.id', '=', 'componentes_donacion.donacion_id')
            ->join('tipos_donacion', 'tipos_donacion.id', '=', 'componentes_donacion.tipo_id')
            ->when(filled($filters['desde'] ?? null), fn ($query) => $query->where('donaciones.fecha', '>=', $filters['desde']))
            ->when(filled($filters['hasta'] ?? null), fn ($query) => $query->where('donaciones.fecha', '<=', $filters['hasta']))
            ->groupBy('tipos_donacion.codigo')
            ->orderBy('tipos_donacion.codigo')
            ->get([
                'tipos_donacion.codigo as codigo',
                DB::raw('count(*) as total'),
            ])
            ->map(fn ($row) => ['codigo' => $row->codigo, 'total' => (int) $row->total])
            ->all();
    }

    /**
     * @return array<string, int>
     */
    private function turnosPorEstado(array $filters): array
    {
        return Turno::query()
            ->when(filled($filters['desde'] ?? null), fn (Builder $query) => $query->where('fecha', '>=', $filters['desde']))
            ->when(filled($filters['hasta'] ?? null), fn (Builder $query) => $query->where('fecha', '<=', $filters['hasta']))
            ->select('estado', DB::raw('count(*) as total'))
            ->groupBy('estado')
            ->orderBy('estado')
            ->pluck('total', 'estado')
            ->map(fn ($total) => (int) $total)
            ->all();
    }
}
