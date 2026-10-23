<?php

namespace App\Http\Controllers;

use App\Http\Requests\Reporte\ResumenReporteRequest;
use App\Services\ReporteService;
use Illuminate\Http\Request;

/** Role access is the `ver-reportes` gate on the routes. */
class ReporteController
{
    public function __construct(
        private ReporteService $reporteService
    ) {}

    public function resumen(ResumenReporteRequest $request)
    {
        $sedeIds = $request->user()->sedesVisiblesIds();

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
        return response()->json($this->reporteService->sedes($request->user()->sedesVisiblesIds()));
    }
}
