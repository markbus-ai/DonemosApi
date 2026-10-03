<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutorizacionExtraordinaria extends Model
{
    use HasFactory;

    protected $table = 'autorizaciones_extraordinarias';

    protected $fillable = ['paciente_id', 'usuario_id', 'motivo', 'fecha'];

    protected $casts = [
        'fecha' => 'datetime',
    ];

    public function paciente(): BelongsTo
    {
        return $this->belongsTo(Paciente::class, 'paciente_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }
}
