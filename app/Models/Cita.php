<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Tabla `citas`. Estados usados por el proyecto (components/admin/status-badge.blade.php):
 * Programada (valor por defecto de la columna), Confirmada, Atendida, Cancelada y No asistió.
 * En el Ciclo 1 se registran citas «Programada» y se cancelan («Cancelada»); confirmar,
 * reprogramar y registrar asistencia corresponden al Ciclo 2.
 */
class Cita extends Model
{
    public const PROGRAMADA = 'Programada';

    public const CONFIRMADA = 'Confirmada';

    public const ATENDIDA = 'Atendida';

    public const CANCELADA = 'Cancelada';

    public const NO_ASISTIO = 'No asistió';

    public const ESTADOS = [self::PROGRAMADA, self::CONFIRMADA, self::ATENDIDA, self::CANCELADA, self::NO_ASISTIO];

    /** Estados que ocupan una franja del médico y del paciente. */
    public const ESTADOS_QUE_OCUPAN = [self::PROGRAMADA, self::CONFIRMADA, self::ATENDIDA, self::NO_ASISTIO];

    /** Estados que todavía se pueden cancelar. */
    public const ESTADOS_CANCELABLES = [self::PROGRAMADA, self::CONFIRMADA];

    protected $table = 'citas';

    protected $primaryKey = 'id_cita';

    public $timestamps = false;

    protected $fillable = [
        'codigo',
        'id_paciente',
        'id_medico',
        'id_especialidad',
        'fecha_cita',
        'hora_inicio',
        'hora_fin',
        'motivo',
        'estado',
        'fecha_creacion',
        'motivo_cancelacion',
        'fecha_cancelacion',
        'cancelado_por',
    ];

    public function paciente(): BelongsTo
    {
        return $this->belongsTo(Paciente::class, 'id_paciente', 'id_paciente');
    }

    public function medico(): BelongsTo
    {
        return $this->belongsTo(Medico::class, 'id_medico', 'id_medico');
    }

    public function especialidad(): BelongsTo
    {
        return $this->belongsTo(Especialidad::class, 'id_especialidad', 'id_especialidad');
    }

    public function canceladoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelado_por', 'id');
    }

    public function historial(): HasMany
    {
        return $this->hasMany(HistorialEstadoCita::class, 'id_cita', 'id_cita')->orderBy('fecha_hora')->orderBy('id_historial');
    }

    public function inicio(): Carbon
    {
        return Carbon::parse($this->fecha_cita . ' ' . substr($this->hora_inicio, 0, 5));
    }

    /** Se puede cancelar si sigue vigente y todavía no empezó. */
    public function esCancelable(): bool
    {
        return in_array($this->estado, self::ESTADOS_CANCELABLES, true) && $this->inicio()->isFuture();
    }
}
