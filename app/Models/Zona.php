<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Zona extends Model
{
    use HasFactory;

    protected $table = 'zonas';

    protected $fillable = ['nombre'];

    public function sedes(): HasMany
    {
        return $this->hasMany(Sede::class, 'zona_id');
    }

    public function usuarios(): HasMany
    {
        return $this->hasMany(Usuario::class, 'zona_id');
    }
}
