<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
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

    /**
     * Locality codes are exactly three digits ("1" is stored as "001"), so the
     * unique index also catches codes that would mint the same numbers.
     */
    protected function codigoLocalidad(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => $value === null || $value === '' ? null : str_pad((string) $value, 3, '0', STR_PAD_LEFT),
        );
    }

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
