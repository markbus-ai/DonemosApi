<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Staff-only domain endpoints: no token, no mutation.
Route::middleware('auth:staff')->group(function () {
    require __DIR__.'/PacienteRouter.php';
    require __DIR__.'/TurnoRouter.php';
    require __DIR__.'/DonacionRouter.php';
    require __DIR__.'/AptitudRouter.php';
    require __DIR__.'/ObservacionRouter.php';
    require __DIR__.'/RestriccionRouter.php';
    require __DIR__.'/TipoDonacionRouter.php';
    require __DIR__.'/MotivoRouter.php';
});

require __DIR__.'/AuthRouter.php';

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
