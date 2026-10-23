<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class Usuario extends Authenticatable
{
    use HasApiTokens, HasFactory;

    public const ROL_ADMIN_SEDE = 'admin-sede';

    public const ROL_ADMIN_ZONA = 'admin-zona';

    protected $table = 'usuarios';

    protected $fillable = ['username', 'password_hash', 'rol_id', 'sede_id', 'zona_id'];

    protected $hidden = ['password_hash'];

    protected function casts(): array
    {
        return [
            'rol_id' => 'integer',
            'sede_id' => 'integer',
            'zona_id' => 'integer',
        ];
    }

    // Staff credentials live in password_hash, not the framework default "password".
    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    public function rol(): BelongsTo
    {
        return $this->belongsTo(Rol::class, 'rol_id');
    }

    // Home site of the staff member; an admin-zona may have none.
    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class, 'sede_id');
    }

    public function zona(): BelongsTo
    {
        return $this->belongsTo(Zona::class, 'zona_id');
    }

    public function puedeVerReportes(): bool
    {
        return in_array($this->rol?->nombre, [self::ROL_ADMIN_SEDE, self::ROL_ADMIN_ZONA], true);
    }

    /**
     * Ids of the sedes whose statistics this user may see: its own sede for
     * admin-sede, every sede of its zona for admin-zona, none otherwise.
     *
     * @return array<int, int>
     */
    public function sedesVisiblesIds(): array
    {
        return match ($this->rol?->nombre) {
            self::ROL_ADMIN_SEDE => $this->sede_id === null ? [] : [$this->sede_id],
            self::ROL_ADMIN_ZONA => $this->zona_id === null
                ? []
                : Sede::where('zona_id', $this->zona_id)->pluck('id')->map(fn ($id) => (int) $id)->all(),
            default => [],
        };
    }

    public function autorizacionesExtraordinarias(): HasMany
    {
        return $this->hasMany(AutorizacionExtraordinaria::class, 'usuario_id');
    }
}
