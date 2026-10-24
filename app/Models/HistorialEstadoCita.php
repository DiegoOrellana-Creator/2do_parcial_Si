<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabla `historial_estado_cita`: cada cambio de estado de una cita.
 */
class HistorialEstadoCita extends Model
{
    protected $table = 'historial_estado_cita';

    protected $primaryKey = 'id_historial';

    public $timestamps = false;

    protected $fillable = [
        'id_cita',
        'estado_anterior',
        'estado_nuevo',
        'fecha_hora',
        'motivo',
        'registrado_por',
    ];

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por', 'id');
    }
}
