<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabla `horario_atencion` (id_horario, id_medico, dia_semana, hora_inicio, hora_final, duracion_cita).
 *
 * dia_semana sigue la norma ISO-8601: 1 = lunes … 7 = domingo (la misma convención que usa
 * PoblacionPruebaSeeder). duracion_cita está en minutos.
 */
class HorarioAtencion extends Model
{
    public const DIAS = [
        1 => 'Lunes',
        2 => 'Martes',
        3 => 'Miércoles',
        4 => 'Jueves',
        5 => 'Viernes',
        6 => 'Sábado',
        7 => 'Domingo',
    ];

    protected $table = 'horario_atencion';

    protected $primaryKey = 'id_horario';

    public $timestamps = false;

    protected $fillable = [
        'id_medico',
        'dia_semana',
        'hora_inicio',
        'hora_final',
        'duracion_cita',
    ];

    protected function casts(): array
    {
        return [
            'dia_semana' => 'integer',
            'duracion_cita' => 'integer',
        ];
    }

    public function medico(): BelongsTo
    {
        return $this->belongsTo(Medico::class, 'id_medico', 'id_medico');
    }

    public function nombreDia(): string
    {
        return self::DIAS[$this->dia_semana] ?? 'Día ' . $this->dia_semana;
    }

    /** Hora en formato HH:MM (la columna es time y llega como HH:MM:SS). */
    public static function hhmm(?string $hora): string
    {
        return $hora === null ? '' : substr($hora, 0, 5);
    }
}
