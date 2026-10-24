<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabla `contacto_emergencia`: un paciente puede tener varios contactos.
 */
class ContactoEmergencia extends Model
{
    protected $table = 'contacto_emergencia';

    protected $primaryKey = 'id_contacto';

    public $timestamps = false;

    protected $fillable = [
        'id_paciente',
        'nombre',
        'parentesco',
        'telefono',
    ];

    public function paciente(): BelongsTo
    {
        return $this->belongsTo(Paciente::class, 'id_paciente', 'id_paciente');
    }
}
