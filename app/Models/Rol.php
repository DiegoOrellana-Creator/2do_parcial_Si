<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Tabla `rol`. Los roles base sostienen los perfiles del sistema: conservan su nombre y solo los
 * roles personalizados se asignan desde Usuarios. Administrador, Médico y Paciente tampoco cambian
 * de permisos: el primero los tiene todos y los otros dos solo identifican un perfil.
 */
class Rol extends Model
{
    public const ADMINISTRADOR = 'Administrador';

    public const RECEPCIONISTA = 'Recepcionista';

    public const MEDICO = 'Médico';

    public const PACIENTE = 'Paciente';

    public const BASE = [self::ADMINISTRADOR, self::RECEPCIONISTA, self::MEDICO, self::PACIENTE];

    protected $table = 'rol';

    protected $primaryKey = 'id_rol';

    public $timestamps = false;

    protected $fillable = ['nombre', 'descripcion'];

    public function permisos(): BelongsToMany
    {
        return $this->belongsToMany(Permiso::class, 'rol_permiso', 'id_rol', 'id_permiso');
    }

    public function esBase(): bool
    {
        return in_array($this->nombre, self::BASE, true);
    }

    public function permisosEditables(): bool
    {
        return ! in_array($this->nombre, [self::ADMINISTRADOR, self::MEDICO, self::PACIENTE], true);
    }

    public function scopePersonalizados(Builder $query): void
    {
        $query->whereNotIn('nombre', self::BASE);
    }
}
