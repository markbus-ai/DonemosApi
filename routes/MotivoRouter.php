<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\MotivoController;

Route::get('/motivos', [MotivoController::class, 'index']);
