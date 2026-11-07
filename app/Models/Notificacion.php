<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabla `notificacion`. Cada aviso se guarda una vez por canal: «Sistema» es el que se ve en la
 * campanita (con `leido`); «Correo» registra el envío por correo y si se pudo entregar.
 */
class Notificacion extends Model
{
    public const SISTEMA = 'Sistema';

    public const CORREO = 'Correo';

    public const ENVIADO = 'Enviado';

    public const FALLIDO = 'Fallido';

    // Tipos de aviso.
    public const CITA_GENERADA = 'Cita generada';

    public const CITA_CANCELADA = 'Cita cancelada';

    public const CITA_REPROGRAMADA = 'Cita reprogramada';

    public const RECORDATORIO = 'Recordatorio';

    public const INASISTENCIA = 'Inasistencia';

    public const INASISTENCIA_CORREGIDA = 'Asistencia corregida';

    public const BLOQUEO = 'Bloqueo';

    public const DESBLOQUEO = 'Desbloqueo';

    protected $table = 'notificacion';

    protected $primaryKey = 'id_notificacion';

    public $timestamps = false;

    protected $fillable = ['id_usuario', 'tipo', 'mensaje', 'canal', 'estado_envio', 'fecha_programada', 'fecha_envio', 'leido'];

    protected function casts(): array
    {
        return ['leido' => 'boolean', 'fecha_programada' => 'datetime', 'fecha_envio' => 'datetime'];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_usuario', 'id');
    }

    /** Avisos que se muestran en la campanita del usuario. */
    public function scopeDe(Builder $query, int $idUsuario): void
    {
        $query->where('id_usuario', $idUsuario)->where('canal', self::SISTEMA);
    }
}
