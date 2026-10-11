<?php

use App\Http\Controllers\ReporteController;
use Illuminate\Support\Facades\Route;

// Read-only aggregate dashboard for the desktop app.
Route::get('/reportes/resumen', [ReporteController::class, 'resumen']);
