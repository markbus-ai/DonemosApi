<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Aptitud extends Model
{
    use HasFactory;

    protected $table = 'aptitudes';

    protected $fillable = ['tipo'];

    public function pacientes(): HasMany
    {
        return $this->hasMany(Paciente::class, 'aptitud_id');
    }
}
