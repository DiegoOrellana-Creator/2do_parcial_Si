<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Tabla `permiso`. Solo se gestionan los que la aplicación comprueba (User::PERMISOS).
 */
class Permiso extends Model
{
    /** Prefijo del código => módulo que se muestra. */
    public const MODULOS = [
        'pacientes' => 'Pacientes',
        'medicos' => 'Médicos',
        'especialidades' => 'Especialidades',
        'horarios' => 'Horarios de atención',
        'disponibilidad' => 'Disponibilidad',
        'citas' => 'Citas',
        'agenda' => 'Agenda',
    ];

    protected $table = 'permiso';

    protected $primaryKey = 'id_permiso';

    public $timestamps = false;

    public function scopeEnUso(Builder $query): void
    {
        $query->whereIn('codigo', User::PERMISOS);
    }

    public function modulo(): string
    {
        return self::MODULOS[strtok($this->codigo, '.')] ?? 'Otros';
    }
}
