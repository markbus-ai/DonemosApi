<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TipoDonacion extends Model
{
    use HasFactory;

    protected $table = 'tipos_donacion';

    protected $fillable = ['nombre', 'codigo', 'es_aferesis'];

    protected $casts = [
        'es_aferesis' => 'boolean',
    ];

    public function donaciones(): HasMany
    {
        return $this->hasMany(Donacion::class, 'tipo_id');
    }

    public function componentes(): HasMany
    {
        return $this->hasMany(ComponenteDonacion::class, 'tipo_id');
    }
}
