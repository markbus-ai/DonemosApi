<?php

use App\Http\Controllers\ReporteController;
use Illuminate\Support\Facades\Route;

// Read-only aggregate dashboard, scoped to the admin's visible sedes.
Route::get('/reportes/resumen', [ReporteController::class, 'resumen']);
Route::get('/reportes/sedes', [ReporteController::class, 'sedes']);
