<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Tabla `especialidad` (id_especialidad, nombre, descripcion, estado).
 *
 * Claves foráneas que la referencian, ambas ON DELETE CASCADE en clinica_db:
 *  - especialidad_medico.id_especialidad (médicos que la ejercen)
 *  - citas.id_especialidad (y, en cascada, pago e historial_estado_cita)
 * Por eso solo se puede eliminar una especialidad sin referencias; si está en uso,
 * se desactiva cambiando `estado`.
 */
class Especialidad extends Model
{
    public const ACTIVA = 'Activo';

    public const INACTIVA = 'Inactivo';

    /** Valores de `estado`: 'Activo' es el valor por defecto de la columna. */
    public const ESTADOS = [self::ACTIVA, self::INACTIVA];

    protected $table = 'especialidad';

    protected $primaryKey = 'id_especialidad';

    public $timestamps = false;

    protected $fillable = [
        'nombre',
        'descripcion',
        'estado',
    ];

    public function totalMedicos(): int
    {
        return DB::table('especialidad_medico')->where('id_especialidad', $this->id_especialidad)->count();
    }

    public function totalCitas(): int
    {
        return DB::table('citas')->where('id_especialidad', $this->id_especialidad)->count();
    }

    /**
     * Motivos que impiden eliminarla (se borrarían en cascada asignaciones y citas).
     *
     * @return list<string>
     */
    public function impedimentosParaEliminar(): array
    {
        $motivos = [];

        if ($medicos = $this->totalMedicos()) {
            $motivos[] = "está asignada a {$medicos} " . ($medicos === 1 ? 'médico' : 'médicos');
        }

        if ($citas = $this->totalCitas()) {
            $motivos[] = "tiene {$citas} " . ($citas === 1 ? 'cita registrada' : 'citas registradas');
        }

        return $motivos;
    }
}
