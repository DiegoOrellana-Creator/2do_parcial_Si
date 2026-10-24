<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabla `informacion_medica`: relación 1 a 1 con `paciente`.
 */
class InformacionMedica extends Model
{
    protected $table = 'informacion_medica';

    protected $primaryKey = 'id_paciente';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'id_paciente',
        'tipo_sangre',
        'alergias',
        'enfermedades_cronicas',
        'medicamentos',
        'antecedentes',
    ];

    public function paciente(): BelongsTo
    {
        return $this->belongsTo(Paciente::class, 'id_paciente', 'id_paciente');
    }
}
