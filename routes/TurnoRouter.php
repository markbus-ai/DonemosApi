<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\RecordatorioController;
use App\Http\Controllers\TurnoController;

Route::get('/turnos', [TurnoController::class, 'index']);
Route::post('/turnos', [TurnoController::class, 'store']);
Route::patch('/turnos/{id}', [TurnoController::class, 'update']);
Route::get('/turnos/{id}/recordatorios', [RecordatorioController::class, 'index']);
Route::post('/turnos/{id}/recordatorio', [RecordatorioController::class, 'store']);
