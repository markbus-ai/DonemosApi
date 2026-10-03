<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Donacion extends Model
{
    use HasFactory;

    protected $table = 'donaciones';

    protected $fillable = [
        'paciente_id',
        'sede_id',
        'tipo_id',
        'fecha',
        'numero_donacion',
        'tipo_bolsa',
        'anticoagulante',
        'lote',
        'tubuladura',
        'brazo',
        'dificultad',
        'operador_id',
        'doble_etiqueta',
    ];

    protected $casts = [
        'doble_etiqueta' => 'boolean',
    ];

    public function paciente(): BelongsTo
    {
        return $this->belongsTo(Paciente::class, 'paciente_id');
    }

    // Site where the donation was collected.
    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class, 'sede_id');
    }

    public function tipoDonacion(): BelongsTo
    {
        return $this->belongsTo(TipoDonacion::class, 'tipo_id');
    }

    // One row per donated bag; quantity equals the row count per type.
    public function componentes(): HasMany
    {
        return $this->hasMany(ComponenteDonacion::class, 'donacion_id');
    }
}
