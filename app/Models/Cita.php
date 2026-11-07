<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;

/**
 * Tabla `citas`. Estados usados por el proyecto (components/admin/status-badge.blade.php):
 * Programada (valor por defecto de la columna), Confirmada, Atendida, Cancelada y No asistió.
 *
 * Una cita nueva se cobra antes de generarse: primero es una reserva «Pendiente de pago» que
 * aparta la franja unos minutos (config pagos.minutos_para_pagar). Al pagarse pasa a «Confirmada»;
 * si vence o se anula queda «Reserva anulada». Las reservas no son citas todavía: el ámbito global
 * «generadas» las oculta de toda consulta Eloquent (listas, agenda, enlaces); para trabajar con ellas
 * se usa Cita::conReservas().
 */
class Cita extends Model
{
    public const PROGRAMADA = 'Programada';

    public const CONFIRMADA = 'Confirmada';

    public const ATENDIDA = 'Atendida';

    public const CANCELADA = 'Cancelada';

    public const NO_ASISTIO = 'No asistió';

    /** Reserva que espera el pago para generar la cita. */
    public const PENDIENTE_PAGO = 'Pendiente de pago';

    /** Reserva que venció o se anuló sin pagarse: nunca llegó a ser una cita. */
    public const RESERVA_ANULADA = 'Reserva anulada';

    public const ESTADOS = [self::PROGRAMADA, self::CONFIRMADA, self::ATENDIDA, self::CANCELADA, self::NO_ASISTIO];

    public const ESTADOS_RESERVA = [self::PENDIENTE_PAGO, self::RESERVA_ANULADA];

    /** Estados que ocupan una franja del médico y del paciente. */
    public const ESTADOS_QUE_OCUPAN = [self::PROGRAMADA, self::CONFIRMADA, self::ATENDIDA, self::NO_ASISTIO];

    /** Estados que todavía se pueden cancelar. */
    public const ESTADOS_CANCELABLES = [self::PROGRAMADA, self::CONFIRMADA];

    /** Minutos de margen sobre el plazo de pago antes de liberar la franja de una reserva. */
    private const MARGEN_RESERVA = 2;

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

    protected static function booted(): void
    {
        static::addGlobalScope('generadas', fn (Builder $q) => $q->whereNotIn($q->getModel()->getTable() . '.estado', self::ESTADOS_RESERVA));
    }

    /** Consulta que incluye también las reservas pendientes de pago y las anuladas. */
    public static function conReservas(): Builder
    {
        return static::withoutGlobalScope('generadas');
    }

    /** Minutos que una reserva aparta la franja. */
    public static function minutosReserva(): int
    {
        return min(1440, max(30, (int) config('pagos.minutos_para_pagar', 30))) + self::MARGEN_RESERVA;
    }

    /**
     * Limita una consulta (Eloquent o DB::table) a las filas que ocupan una franja: las citas no
     * canceladas y las reservas pendientes de pago que todavía no vencieron.
     */
    public static function soloQueOcupan(Builder|QueryBuilder $q, string $tabla = 'citas'): Builder|QueryBuilder
    {
        return $q->whereNotIn("{$tabla}.estado", [self::CANCELADA, self::RESERVA_ANULADA])
            ->where(fn ($sub) => $sub->where("{$tabla}.estado", '<>', self::PENDIENTE_PAGO)
                ->orWhere("{$tabla}.fecha_creacion", '>', now()->subMinutes(self::minutosReserva())));
    }

    /** Excluye las reservas de una consulta DB::table (el ámbito global solo actúa en Eloquent). */
    public static function sinReservas(QueryBuilder $q, string $tabla = 'citas'): QueryBuilder
    {
        return $q->whereNotIn("{$tabla}.estado", self::ESTADOS_RESERVA);
    }

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

    public function pagos(): HasMany
    {
        return $this->hasMany(Pago::class, 'id_cita', 'id_cita')->orderBy('id_pago');
    }

    public function inicio(): Carbon
    {
        return Carbon::parse($this->fecha_cita . ' ' . substr($this->hora_inicio, 0, 5));
    }

    public function esReserva(): bool
    {
        return $this->estado === self::PENDIENTE_PAGO;
    }

    /** Hora en que la reserva deja de apartar la franja. */
    public function venceReserva(): Carbon
    {
        return Carbon::parse($this->fecha_creacion)->addMinutes(self::minutosReserva());
    }

    /** Reserva que todavía se puede pagar: no venció y la cita no empezó. */
    public function reservaVigente(): bool
    {
        return $this->esReserva() && $this->venceReserva()->isFuture() && $this->inicio()->isFuture();
    }

    /** Se confirma una cita programada que todavía no empezó. */
    public function puedeConfirmarse(): bool
    {
        return $this->estado === self::PROGRAMADA && $this->inicio()->isFuture();
    }

    /** La asistencia se registra en una cita vigente cuando ya llegó su hora. */
    public function puedeRegistrarAsistencia(): bool
    {
        return in_array($this->estado, self::ESTADOS_CANCELABLES, true) && ! $this->inicio()->isFuture();
    }

    /** Precio de la consulta si la especialidad tiene uno mayor que cero. */
    public function precioCobrable(): ?float
    {
        $precio = $this->especialidad?->precio;

        return $precio !== null && (float) $precio > 0 ? (float) $precio : null;
    }

    public function estaPagada(): bool
    {
        return $this->pagos()->where('estado_pago', Pago::PAGADO)->exists();
    }

    /** En línea se paga una reserva vigente o una cita vigente que todavía no empezó. */
    public function admitePagoEnLinea(): bool
    {
        return ($this->reservaVigente() || $this->esCancelable()) && $this->precioCobrable() !== null && ! $this->estaPagada();
    }

    /** La recepción cobra una reserva vigente o una cita no cancelada ni perdida (también ya atendida). */
    public function admitePagoEnVentanilla(): bool
    {
        return ($this->reservaVigente() || in_array($this->estado, [self::PROGRAMADA, self::CONFIRMADA, self::ATENDIDA], true))
            && $this->precioCobrable() !== null && ! $this->estaPagada();
    }

    /** Se puede cancelar si sigue vigente y todavía no empezó. */
    public function esCancelable(): bool
    {
        return in_array($this->estado, self::ESTADOS_CANCELABLES, true) && $this->inicio()->isFuture();
    }
}
