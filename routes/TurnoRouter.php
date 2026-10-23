<?php

use App\Http\Controllers\RecordatorioController;
use App\Http\Controllers\TurnoController;
use Illuminate\Support\Facades\Route;

Route::get('/turnos', [TurnoController::class, 'index']);
Route::post('/turnos', [TurnoController::class, 'store']);
Route::patch('/turnos/{turno}', [TurnoController::class, 'update']);
Route::get('/turnos/{turno}/recordatorios', [RecordatorioController::class, 'index']);
Route::post('/turnos/{turno}/recordatorio', [RecordatorioController::class, 'store']);
