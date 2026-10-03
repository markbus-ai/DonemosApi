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
        'hemoglobina',
        'hematocrito',
        'plaquetas',
        'presion_sistolica',
        'presion_diastolica',
        'frecuencia_cardiaca',
        'peso_donante',
    ];

    protected $casts = [
        'doble_etiqueta' => 'boolean',
        'hemoglobina' => 'decimal:2',
        'hematocrito' => 'decimal:2',
        'plaquetas' => 'integer',
        'presion_sistolica' => 'integer',
        'presion_diastolica' => 'integer',
        'frecuencia_cardiaca' => 'integer',
        'peso_donante' => 'decimal:2',
    ];

    protected $appends = ['presion_arterial'];

    // Serialized pressure string; null unless both mmHg values are present.
    public function getPresionArterialAttribute(): ?string
    {
        if ($this->presion_sistolica === null || $this->presion_diastolica === null) {
            return null;
        }

        return $this->presion_sistolica.'/'.$this->presion_diastolica;
    }

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
