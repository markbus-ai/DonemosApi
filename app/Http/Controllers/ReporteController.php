<?php

namespace App\Http\Controllers;

use App\Services\ReporteService;
use Illuminate\Http\Request;

class ReporteController
{
    public function __construct(
        private ReporteService $reporteService
    ) {}

    public function resumen(Request $request)
    {
        return response()->json(
            $this->reporteService->resumen($request->only(['desde', 'hasta']))
        );
    }
}
