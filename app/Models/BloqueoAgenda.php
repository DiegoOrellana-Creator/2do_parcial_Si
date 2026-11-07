<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Tabla `bloqueo_agenda`: periodo de un día en el que un médico no atiende.
 * Disponibilidad no ofrece las franjas que coinciden con un bloqueo.
 */
class BloqueoAgenda extends Model
{
    protected $table = 'bloqueo_agenda';

    protected $primaryKey = 'id_bloqueo';

    public $timestamps = false;

    protected $fillable = [
        'id_medico',
        'fecha',
        'hora_inicio',
        'hora_final',
        'motivo',
    ];

    public function medico(): BelongsTo
    {
        return $this->belongsTo(Medico::class, 'id_medico', 'id_medico');
    }

    /** Ya terminó: se conserva como historial. */
    public function haFinalizado(): bool
    {
        return Carbon::parse(substr($this->fecha, 0, 10) . ' ' . substr($this->hora_final, 0, 5))->isPast();
    }
}
