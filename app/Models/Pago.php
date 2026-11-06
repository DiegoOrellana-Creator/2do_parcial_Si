<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabla `pago`. `monto` guarda el importe cobrado: un cambio posterior del precio de la
 * especialidad no altera los pagos ya registrados. Una cita puede tener varios intentos de pago.
 */
class Pago extends Model
{
    /** Valor por defecto de la columna `estado_pago`. */
    public const PENDIENTE = 'Pendiente';

    public const PAGADO = 'Pagado';

    protected $table = 'pago';

    protected $primaryKey = 'id_pago';

    public $timestamps = false;

    protected $fillable = [
        'id_cita',
        'monto',
        'metodo_de_pago',
        'id_transaccion',
        'estado_pago',
        'fecha_pago',
    ];

    protected function casts(): array
    {
        return [
            'monto' => 'decimal:2',
            'fecha_pago' => 'datetime',
        ];
    }

    public function cita(): BelongsTo
    {
        return $this->belongsTo(Cita::class, 'id_cita', 'id_cita');
    }
}
