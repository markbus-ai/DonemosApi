<?php

namespace App\Http\Controllers;

use App\Http\Resources\TipoDonacionResource;
use App\Models\TipoDonacion;

class TipoDonacionController
{
    public function index()
    {
        return response()->json(
            TipoDonacionResource::collection(TipoDonacion::orderBy('id')->get())
        );
    }
}
