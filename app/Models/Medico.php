<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Tabla `medico`: subtipo de `users`. Su clave primaria `id_medico` es a la vez users.id.
 * No tiene columna de estado: el estado del médico es el de su cuenta (users.estado).
 *
 * Claves foráneas que la referencian, todas ON DELETE CASCADE en clinica_db:
 * especialidad_medico, horario_atencion, bloqueo_agenda y citas (y con ellas pago e historial).
 */
class Medico extends Model
{
    protected $table = 'medico';

    protected $primaryKey = 'id_medico';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'id_medico',
        'matricula',
        'codigo',
    ];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_medico', 'id');
    }

    public function especialidades(): BelongsToMany
    {
        return $this->belongsToMany(Especialidad::class, 'especialidad_medico', 'id_medico', 'id_especialidad')
            ->orderBy('especialidad.nombre');
    }

    public function estaActivo(): bool
    {
        return $this->usuario?->estado === User::ESTADO_ACTIVO;
    }
}
