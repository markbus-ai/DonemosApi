<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Paciente extends Model
{
    use HasFactory;

    protected $table = 'pacientes';

    protected $fillable = [
        'dni',
        'nombre',
        'apellido',
        'telefono',
        'sexo',
        'altura',
        // aptitud_id: default APTO se asigna en Service/Observer, no en DB
        'aptitud_id',
    ];

    protected $casts = [
        'altura' => 'decimal:1',
    ];

    public function aptitud(): BelongsTo
    {
        return $this->belongsTo(Aptitud::class, 'aptitud_id');
    }

    public function observaciones(): HasMany
    {
        return $this->hasMany(Observacion::class, 'paciente_id');
    }

    /**
     * Full deferral history; rows are closed, never deleted.
     */
    public function restricciones(): HasMany
    {
        return $this->hasMany(Restriccion::class, 'paciente_id');
    }

    /**
     * At most one active deferral as of today.
     */
    public function activeDeferral(): HasOne
    {
        return $this->hasOne(Restriccion::class, 'paciente_id')
            ->vigente(now()->toDateString())
            ->latest('desde');
    }

    /**
     * Platelet-enable history; rows are closed, never deleted.
     */
    public function habilitacionesPlaquetas(): HasMany
    {
        return $this->hasMany(HabilitacionPlaqueta::class, 'paciente_id');
    }

    public function donaciones(): HasMany
    {
        return $this->hasMany(Donacion::class, 'paciente_id');
    }

    public function turnos(): HasMany
    {
        return $this->hasMany(Turno::class, 'paciente_id');
    }

    public function autorizacionesExtraordinarias(): HasMany
    {
        return $this->hasMany(AutorizacionExtraordinaria::class, 'paciente_id');
    }
}
