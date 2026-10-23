<?php

use App\Http\Controllers\MotivoController;
use Illuminate\Support\Facades\Route;

Route::get('/motivos', [MotivoController::class, 'index']);
