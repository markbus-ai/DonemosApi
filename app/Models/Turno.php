<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Turno extends Model
{
    use HasFactory;

    protected $table = 'turnos';

    protected $fillable = ['paciente_id', 'tipo_id', 'sede_id', 'fecha', 'hora', 'estado'];

    protected $casts = [
        'fecha' => 'date',
        'hora' => 'datetime:H:i',
    ];

    public function paciente(): BelongsTo
    {
        return $this->belongsTo(Paciente::class, 'paciente_id');
    }

    // Site where the appointment takes place.
    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class, 'sede_id');
    }

    // Donation type requested for the appointment; null for legacy rows.
    public function tipoDonacion(): BelongsTo
    {
        return $this->belongsTo(TipoDonacion::class, 'tipo_id');
    }
}
