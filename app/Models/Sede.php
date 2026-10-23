<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sede extends Model
{
    use HasFactory;

    protected $table = 'sedes';

    protected $fillable = ['nombre', 'codigo_localidad', 'direccion', 'telefono', 'activa', 'zona_id'];

    protected $casts = [
        'activa' => 'boolean',
        'zona_id' => 'integer',
    ];

    public function zona(): BelongsTo
    {
        return $this->belongsTo(Zona::class, 'zona_id');
    }

    public function turnos(): HasMany
    {
        return $this->hasMany(Turno::class, 'sede_id');
    }

    public function donaciones(): HasMany
    {
        return $this->hasMany(Donacion::class, 'sede_id');
    }

    public function usuarios(): HasMany
    {
        return $this->hasMany(Usuario::class, 'sede_id');
    }
}
