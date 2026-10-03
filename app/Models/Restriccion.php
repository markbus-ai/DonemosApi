<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Restriccion extends Model
{
    use HasFactory;

    protected $table = 'restricciones';

    protected $fillable = ['paciente_id', 'motivo_id', 'desde', 'hasta'];

    public function paciente(): BelongsTo
    {
        return $this->belongsTo(Paciente::class, 'paciente_id');
    }

    public function motivo(): BelongsTo
    {
        return $this->belongsTo(Motivo::class, 'motivo_id');
    }
}
