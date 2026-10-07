<?php

namespace App\Http\Controllers;

use App\Http\Resources\MotivoResource;
use App\Models\Motivo;

class MotivoController
{
    public function index()
    {
        return response()->json(
            MotivoResource::collection(Motivo::orderBy('nombre')->get())
        );
    }
}
