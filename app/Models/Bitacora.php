<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Registro de auditoría (tabla `bitacora`). Solo se añaden filas: la aplicación nunca
 * modifica ni elimina un registro existente. Se escribe a través de App\Services\Auditoria.
 */
class Bitacora extends Model
{
    protected $table = 'bitacora';

    protected $primaryKey = 'id_bitacora';

    public $timestamps = false;

    protected $fillable = ['id_usuario', 'fecha_hora', 'accion', 'modulo', 'id_registro', 'detalle', 'ip'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Los registros de la bitácora no se pueden modificar.'));
        static::deleting(fn () => throw new LogicException('Los registros de la bitácora no se pueden eliminar.'));
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_usuario');
    }
}
