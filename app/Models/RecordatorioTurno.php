<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecordatorioTurno extends Model
{
    use HasFactory;

    protected $table = 'recordatorios_turno';

    protected $fillable = [
        'turno_id',
        'canal',
        'estado',
        'contenido',
        'intento',
        'sent_at',
    ];

    protected $casts = [
        'intento' => 'integer',
        'sent_at' => 'datetime',
    ];

    public function turno(): BelongsTo
    {
        return $this->belongsTo(Turno::class, 'turno_id');
    }
}
