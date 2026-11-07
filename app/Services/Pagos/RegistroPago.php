<?php

namespace App\Services\Pagos;

use App\Models\Cita;
use App\Models\HistorialEstadoCita;
use App\Models\Paciente;
use App\Models\Pago;
use App\Services\Auditoria;
use App\Services\Notificador;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * Pagos de las citas: en línea (por la pasarela configurada) o en ventanilla (recepción).
 * El importe es el precio de la especialidad en el momento de pagar. Un pago completado confirma la
 * cita si seguía «Programada». Cancelar una cita pagada no devuelve el dinero automáticamente.
 * Cada operación devuelve ['status' => mensaje] o ['error' => mensaje].
 */
class RegistroPago
{
    /** Métodos que registra la recepción a mano. */
    public const METODOS_VENTANILLA = ['Efectivo', 'QR bancario'];

    /** En ventanilla, la tarjeta se cobra en la pasarela (se abre en la pantalla de la recepción). */
    public const TARJETA = 'Tarjeta';

    /** Caché con la dirección de la pasarela de cada pago pendiente. */
    private const CLAVE_SESION = 'pago-sesion:';

    public function __construct(private PasarelaPago $pasarela) {}

    public function pasarela(): PasarelaPago
    {
        return $this->pasarela;
    }

    /**
     * Dirección de la pasarela de un pago pendiente y hora en que vence (para mostrarla en un QR).
     *
     * @return array{url: string, vence: \Illuminate\Support\Carbon}|null
     */
    public function sesionAbierta(Pago $pago): ?array
    {
        $sesion = $pago->estado_pago === Pago::PENDIENTE ? Cache::get(self::CLAVE_SESION . $pago->id_pago) : null;

        return $sesion ? ['url' => $sesion['url'], 'vence' => Carbon::createFromTimestamp($sesion['vence'])] : null;
    }

    /**
     * Abre el pago en línea de una cita y devuelve la dirección de la pasarela.
     * Con $conQr la dirección se muestra como código QR para pagar desde otro dispositivo (el
     * celular, sin sesión iniciada): al terminar vuelve a páginas públicas firmadas.
     * Con $enVentanilla lo genera la recepción y vale también para citas ya atendidas.
     *
     * @return array{url?: string, pago?: Pago, status?: string, error?: string}
     */
    public function iniciarEnLinea(Cita $cita, bool $conQr = false, bool $enVentanilla = false): array
    {
        if (! $this->pasarela->disponible()) {
            return ['error' => 'El pago en línea no está disponible en este momento. Puedes pagar en recepción.'];
        }

        // Una sesión anterior sin terminar se cierra antes de abrir otra, para no cobrar dos veces.
        foreach (Pago::where('id_cita', $cita->id_cita)->where('estado_pago', Pago::PENDIENTE)->whereNotNull('id_transaccion')->get() as $anterior) {
            if ($this->verificar($anterior)) {
                return ['status' => 'Tu pago anterior ya se había completado: la cita está pagada.'];
            }
            if ($anterior->fresh()->estado_pago === Pago::PENDIENTE) {
                $this->cerrar($anterior, 'Se inició un nuevo intento de pago.');
            }
        }

        $pago = DB::transaction(function () use ($cita, $conQr, $enVentanilla) {
            $fila = Cita::conReservas()->with('especialidad')->whereKey($cita->id_cita)->lockForUpdate()->first();

            if (! $fila || ! ($enVentanilla ? $fila->admitePagoEnVentanilla() : $fila->admitePagoEnLinea())) {
                return null;
            }

            return Pago::create([
                'id_cita' => $fila->id_cita,
                'monto' => $fila->especialidad->precio,
                'metodo_de_pago' => mb_substr(($conQr ? 'QR · ' : '') . $this->pasarela->nombre(), 0, 50),
                'estado_pago' => Pago::PENDIENTE,
            ]);
        });

        if (! $pago) {
            return ['error' => 'Esta cita no se puede pagar en línea: ya está pagada, no tiene precio o ya no está vigente.'];
        }

        $minutos = min(1440, max(30, (int) config('pagos.minutos_para_pagar', 30)));
        $vence = now()->addMinutes($minutos);
        [$retorno, $cancelado] = $conQr
            ? [URL::temporarySignedRoute('pagos.qr.listo', $vence->copy()->addHour(), ['pago' => $pago->id_pago]),
                URL::temporarySignedRoute('pagos.qr.cancelado', $vence->copy()->addHour(), ['pago' => $pago->id_pago])]
            : ($enVentanilla
                ? [route('citas.pago.retorno', $pago), route('citas.pago.cancelado', $pago)]
                : [route('mis-citas.pago.retorno', $pago), route('mis-citas.pago.cancelado', $pago)]);

        try {
            $sesion = $this->pasarela->iniciar($pago, $cita, $retorno, $cancelado);
        } catch (Throwable $e) {
            Log::warning('No se pudo iniciar el pago ' . $pago->id_pago . ': ' . $e->getMessage());
            $pago->update(['estado_pago' => Pago::FALLIDO]);

            return ['error' => 'No se pudo conectar con la pasarela de pago. Inténtalo más tarde o paga en recepción.'];
        }

        $pago->update(['id_transaccion' => mb_substr($sesion['referencia'], 0, 100)]);
        Cache::put(self::CLAVE_SESION . $pago->id_pago, ['url' => $sesion['url'], 'vence' => $vence->getTimestamp()], $vence);

        return ['url' => $sesion['url'], 'pago' => $pago];
    }

    /**
     * Pregunta a la pasarela por un pago pendiente y lo registra si ya se cobró.
     * Devuelve true si el pago queda «Pagado».
     */
    public function verificar(Pago $pago): bool
    {
        if ($pago->estado_pago === Pago::PAGADO) {
            return true;
        }
        if ($pago->estado_pago !== Pago::PENDIENTE || blank($pago->id_transaccion)) {
            return false;
        }

        try {
            $estado = $this->pasarela->consultar($pago->id_transaccion);
        } catch (Throwable $e) {
            Log::warning('No se pudo consultar el pago ' . $pago->id_pago . ': ' . $e->getMessage());

            return false;
        }

        if ($estado['estado'] === 'pagado') {
            return $this->confirmar($pago, $estado['monto']);
        }
        if ($estado['estado'] === 'cerrado') {
            $this->marcarFallido($pago, 'La sesión de pago expiró sin completarse.');
        }

        return false;
    }

    /**
     * Registra como cobrado un pago en línea. Repetirlo no duplica nada (la pasarela puede avisar
     * más de una vez). Devuelve true si el pago queda «Pagado».
     */
    public function confirmar(Pago $pago, ?float $montoCobrado): bool
    {
        return DB::transaction(function () use ($pago, $montoCobrado) {
            // Mismo orden de bloqueo que el resto de operaciones sobre citas: paciente, cita y pago.
            $cita = Cita::conReservas()->find($pago->id_cita);
            Paciente::whereKey($cita?->id_paciente)->lockForUpdate()->first();
            $cita = Cita::conReservas()->whereKey($pago->id_cita)->lockForUpdate()->first();
            $fila = Pago::whereKey($pago->id_pago)->lockForUpdate()->first();

            if (! $fila || ! $cita) {
                return false;
            }
            if ($fila->estado_pago === Pago::PAGADO) {
                return true;
            }

            if ($montoCobrado !== null && abs($montoCobrado - (float) $fila->monto) > 0.005) {
                Auditoria::registrar(Auditoria::PAGO, Auditoria::PAGOS, (int) $fila->id_pago,
                    "Pago de la cita {$cita->codigo} NO registrado: la pasarela cobró {$this->bs($montoCobrado)} y se esperaba {$this->bs((float) $fila->monto)}. Revisar en la pasarela.",
                    (int) $cita->id_paciente);

                return false;
            }

            $yaPagada = Pago::where('id_cita', $cita->id_cita)->where('estado_pago', Pago::PAGADO)->exists();
            $fila->update(['estado_pago' => Pago::PAGADO, 'fecha_pago' => now()]);

            $detalle = "Pago en línea de la cita {$cita->codigo}: {$this->bs((float) $fila->monto)} ({$fila->metodo_de_pago}, ref. {$fila->id_transaccion}).";
            if ($yaPagada) {
                $detalle .= ' ATENCIÓN: la cita ya tenía otro pago; corresponde revisar una devolución.';
            } elseif (in_array($cita->estado, [Cita::CANCELADA, Cita::RESERVA_ANULADA], true)) {
                $detalle .= ' ATENCIÓN: la ' . ($cita->estado === Cita::CANCELADA ? 'cita estaba cancelada' : 'reserva ya había vencido')
                    . '; corresponde revisar una devolución.';
            }
            Auditoria::registrar(Auditoria::PAGO, Auditoria::PAGOS, (int) $fila->id_pago, $detalle, (int) $cita->id_paciente);

            $this->confirmarCita($cita, (int) $cita->id_paciente, 'Pago en línea recibido.', $fila);
            $this->cerrarOtrosIntentos($fila);

            return true;
        });
    }

    /**
     * El paciente volvió de la pasarela sin pagar: se cierra la sesión.
     */
    public function cancelarEnLinea(Pago $pago): void
    {
        if (! $this->verificar($pago) && $pago->fresh()->estado_pago === Pago::PENDIENTE) {
            $this->cerrar($pago, 'El paciente canceló el pago.');
        }
    }

    public function marcarFallido(Pago $pago, string $motivo): void
    {
        $actualizados = Pago::whereKey($pago->id_pago)->where('estado_pago', Pago::PENDIENTE)->update(['estado_pago' => Pago::FALLIDO]);

        if ($actualizados) {
            Auditoria::registrar(Auditoria::PAGO, Auditoria::PAGOS, (int) $pago->id_pago,
                "Intento de pago #{$pago->id_pago} sin completar: {$motivo}", (int) Cita::conReservas()->whereKey($pago->id_cita)->value('id_paciente'));
        }
    }

    /**
     * Cobro registrado por la recepción (efectivo, QR o tarjeta en ventanilla).
     *
     * @return array{status?: string, error?: string}
     */
    public function registrarEnVentanilla(Cita $cita, string $metodo, ?string $comprobante, int $registradoPor): array
    {
        return DB::transaction(function () use ($cita, $metodo, $comprobante, $registradoPor) {
            Paciente::whereKey($cita->id_paciente)->lockForUpdate()->first();
            $fila = Cita::conReservas()->with('especialidad')->whereKey($cita->id_cita)->lockForUpdate()->first();

            if (! $fila || ! $fila->admitePagoEnVentanilla()) {
                return ['error' => 'Esta cita no se puede cobrar: ya está pagada, no tiene precio o fue cancelada.'];
            }

            $pago = Pago::create([
                'id_cita' => $fila->id_cita,
                'monto' => $fila->especialidad->precio,
                'metodo_de_pago' => $metodo,
                'id_transaccion' => filled($comprobante) ? $comprobante : null,
                'estado_pago' => Pago::PAGADO,
                'fecha_pago' => now(),
            ]);

            Auditoria::registrar(Auditoria::PAGO, Auditoria::PAGOS, (int) $pago->id_pago,
                "Pago en ventanilla de la cita {$fila->codigo}: {$this->bs((float) $pago->monto)} ({$metodo}"
                . (filled($comprobante) ? ", comprobante {$comprobante}" : '') . ').', $registradoPor);

            $generada = $fila->esReserva();
            $this->confirmarCita($fila, $registradoPor, "Pago en ventanilla recibido ({$metodo}).", $pago);
            $this->cerrarOtrosIntentos($pago);

            return ['status' => $generada
                ? "Pago de {$this->bs((float) $pago->monto)} registrado: se generó la cita {$fila->codigo}."
                : "Pago de {$this->bs((float) $pago->monto)} registrado para la cita {$fila->codigo}."];
        });
    }

    /**
     * Ya cobrada la cita, cierra los demás intentos en línea abiertos (otro QR o una página de
     * pago con tarjeta) para que no se cobre dos veces. Se hace tras confirmar la transacción,
     * porque consulta a la pasarela.
     */
    private function cerrarOtrosIntentos(Pago $cobrado): void
    {
        DB::afterCommit(function () use ($cobrado) {
            Pago::where('id_cita', $cobrado->id_cita)->where('id_pago', '<>', $cobrado->id_pago)
                ->where('estado_pago', Pago::PENDIENTE)->whereNotNull('id_transaccion')
                ->get()
                ->each(fn (Pago $otro) => $this->cerrar($otro, 'La cita ya se pagó por otro medio.'));
        });
    }

    /** Cierra la sesión en la pasarela y marca el intento como fallido. */
    private function cerrar(Pago $pago, string $motivo): void
    {
        try {
            $this->pasarela->anular($pago->id_transaccion);
            $estado = $this->pasarela->consultar($pago->id_transaccion);
        } catch (Throwable $e) {
            Log::warning('No se pudo cerrar la sesión del pago ' . $pago->id_pago . ': ' . $e->getMessage());
            $estado = null;
        }

        // Si entre tanto se completó, se registra el cobro en vez de descartarlo. Si no se pudo
        // consultar y luego llega el aviso de la pasarela, confirmar() lo registra igualmente.
        if ($estado && $estado['estado'] === 'pagado') {
            $this->confirmar($pago, $estado['monto']);
        } else {
            $this->marcarFallido($pago, $motivo);
        }
    }

    /**
     * El pago genera la cita de una reserva pendiente, o confirma una cita que seguía «Programada».
     * Una reserva ya anulada no se revive: su franja pudo ocuparse (el pago queda para devolver).
     */
    private function confirmarCita(Cita $cita, int $registradoPor, string $motivo, Pago $pago): void
    {
        if ($cita->esReserva()) {
            $cita->update(['estado' => Cita::CONFIRMADA]);
            $this->historial($cita, Cita::PENDIENTE_PAGO, $registradoPor, "{$motivo} Cita generada.");
            Auditoria::registrar(Auditoria::CREAR, Auditoria::CITAS, (int) $cita->id_cita,
                "Cita {$cita->codigo} generada tras el pago: " . Cita::PENDIENTE_PAGO . ' → ' . Cita::CONFIRMADA . '.', $registradoPor);
            app(Notificador::class)->citaGenerada($cita, $pago);

            return;
        }

        if (! $cita->puedeConfirmarse()) {
            return;
        }

        $cita->update(['estado' => Cita::CONFIRMADA]);
        $this->historial($cita, Cita::PROGRAMADA, $registradoPor, $motivo);
        Auditoria::registrar(Auditoria::CAMBIO_ESTADO, Auditoria::CITAS, (int) $cita->id_cita,
            "Cita {$cita->codigo}: " . Cita::PROGRAMADA . ' → ' . Cita::CONFIRMADA . ". {$motivo}", $registradoPor);
    }

    private function historial(Cita $cita, string $anterior, int $registradoPor, string $motivo): void
    {
        HistorialEstadoCita::create([
            'id_cita' => $cita->id_cita,
            'estado_anterior' => $anterior,
            'estado_nuevo' => Cita::CONFIRMADA,
            'fecha_hora' => now(),
            'motivo' => $motivo,
            'registrado_por' => $registradoPor,
        ]);
    }

    private function bs(float $monto): string
    {
        return 'Bs ' . number_format($monto, 2, ',', '.');
    }
}
