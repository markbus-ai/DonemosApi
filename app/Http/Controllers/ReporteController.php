<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use App\Services\ReporteService;
use Illuminate\Http\Request;

class ReporteController
{
    public function __construct(
        private ReporteService $reporteService
    ) {}

    public function resumen(Request $request)
    {
        $sedeIds = $this->sedesVisibles($request->user());

        $request->validate([
            'sede_id' => ['nullable', 'integer', 'min:1'],
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
        ]);

        // An explicit sede narrows the scope and must be one the caller may see.
        if ($request->filled('sede_id')) {
            $sedeId = (int) $request->input('sede_id');

            abort_unless(in_array($sedeId, $sedeIds, true), 403, 'No tenés acceso a esa sede.');

            $sedeIds = [$sedeId];
        }

        return response()->json(
            $this->reporteService->resumen([...$request->only(['desde', 'hasta']), 'sede_ids' => $sedeIds])
        );
    }

    public function sedes(Request $request)
    {
        return response()->json(
            $this->reporteService->sedes($this->sedesVisibles($request->user()))
        );
    }

    /**
     * @return array<int, int>
     */
    private function sedesVisibles(Usuario $usuario): array
    {
        abort_unless($usuario->puedeVerReportes(), 403, 'Tu rol no permite ver estadísticas.');

        return $usuario->sedesVisiblesIds();
    }
}
