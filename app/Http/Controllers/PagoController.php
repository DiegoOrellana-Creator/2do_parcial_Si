<?php

namespace App\Http\Controllers;

use App\Models\Cita;
use App\Models\Pago;
use App\Services\Pagos\PasarelaSimulada;
use App\Services\Pagos\PasarelaStripe;
use App\Services\Pagos\RegistroPago;
use App\Services\RegistroCita;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Cobro de citas. Una cita nueva es primero una reserva «Pendiente de pago»: se genera cuando se
 * paga. El paciente paga las suyas con tarjeta o con un QR que abre la pasarela en su celular (rutas
 * con perfil «paciente»); la recepción cobra en ventanilla o muestra el QR (Gate «pagos.registrar»);
 * Stripe avisa por el webhook. Las páginas que se abren desde el QR son públicas y van firmadas
 * (middleware «signed»): no exponen datos del paciente.
 *
 * El parámetro {cobro} es una cita o una reserva pendiente (ver routes/web.php).
 */
class PagoController extends Controller
{
    public function __construct(private RegistroPago $pagos) {}

    // --- Paciente ------------------------------------------------------------------

    /** Pantalla de pago de una reserva: hasta pagarla no hay cita. */
    public function reservaPaciente(Request $request, Cita $cobro, RegistroCita $registro): View|RedirectResponse
    {
        $this->propia($request, $cobro);

        return $this->pantallaReserva($cobro, $registro, false);
    }

    public function anularReservaPaciente(Request $request, Cita $cobro, RegistroCita $registro): RedirectResponse
    {
        $this->propia($request, $cobro);
        $resultado = $registro->anularReserva($cobro, 'El paciente anuló la solicitud antes de pagar.', $request->user()->id);

        return $this->volver(route('mis-citas.index'), $resultado);
    }

    public function pagar(Request $request, Cita $cobro): RedirectResponse
    {
        $this->propia($request, $cobro);

        $resultado = $this->pagos->iniciarEnLinea($cobro);

        if (isset($resultado['url'])) {
            return redirect()->away($resultado['url']);
        }

        return $this->volver($this->destino($cobro->fresh(), false), $resultado);
    }

    public function pagarConQr(Request $request, Cita $cobro): RedirectResponse
    {
        $this->propia($request, $cobro);

        $resultado = $this->pagos->iniciarEnLinea($cobro, conQr: true);

        return isset($resultado['pago'])
            ? redirect()->route('mis-citas.pago.qr', $resultado['pago'])
            : $this->volver($this->destino($cobro->fresh(), false), $resultado);
    }

    public function qrPaciente(Request $request, Pago $pago): View|RedirectResponse
    {
        $this->propia($request, $pago->cita);

        return $this->pantallaQr($pago, false, route('mis-citas.pago.qr', $pago), route('mis-citas.pago.cancelado', $pago), 'GET');
    }

    public function retorno(Request $request, Pago $pago): RedirectResponse
    {
        $cita = $pago->cita;
        $this->propia($request, $cita);

        return $this->pagos->verificar($pago)
            ? redirect()->route('mis-citas.show', $cita)->with('status', "Pago recibido. ¡Gracias! Tu cita {$cita->codigo} quedó registrada y confirmada.")
            : redirect()->to($this->destino($cita->fresh(), false))->with('error', 'Todavía no recibimos la confirmación del pago. Si ya pagaste, se reflejará en unos minutos.');
    }

    public function cancelado(Request $request, Pago $pago): RedirectResponse
    {
        $cita = $pago->cita;
        $this->propia($request, $cita);
        $this->pagos->cancelarEnLinea($pago);

        return $pago->fresh()->estado_pago === Pago::PAGADO
            ? redirect()->route('mis-citas.show', $cita)->with('status', "Pago recibido. Tu cita {$cita->codigo} quedó registrada.")
            : redirect()->to($this->destino($cita->fresh(), false))->with('error', 'Cancelaste el pago: no se realizó ningún cobro.');
    }

    // --- Recepción -------------------------------------------------------------------

    public function cobroVentanilla(Cita $cobro, RegistroCita $registro): View|RedirectResponse
    {
        return $this->pantallaReserva($cobro, $registro, true);
    }

    public function anularReservaVentanilla(Request $request, Cita $cobro, RegistroCita $registro): RedirectResponse
    {
        $resultado = $registro->anularReserva($cobro, 'El personal anuló la reserva antes del cobro.', $request->user()->id);

        return $this->volver(route('citas.index'), $resultado);
    }

    public function ventanilla(Request $request, Cita $cobro): RedirectResponse
    {
        $datos = $request->validateWithBag('pago', [
            'metodo' => ['required', Rule::in([...RegistroPago::METODOS_VENTANILLA, RegistroPago::TARJETA])],
            'comprobante' => ['nullable', 'string', 'max:100'],
        ], [
            'metodo.required' => 'Elige el método de pago.',
            'metodo.in' => 'El método de pago no es válido.',
            'comprobante.max' => 'El comprobante no debe superar los :max caracteres.',
        ]);

        // Con tarjeta se abre la pasarela en esta misma pantalla; al terminar vuelve a la recepción.
        if ($datos['metodo'] === RegistroPago::TARJETA) {
            $resultado = $this->pagos->iniciarEnLinea($cobro, enVentanilla: true);

            return isset($resultado['url'])
                ? redirect()->away($resultado['url'])
                : $this->volver($this->destino($cobro->fresh(), true), $resultado);
        }

        $resultado = $this->pagos->registrarEnVentanilla($cobro, $datos['metodo'], $datos['comprobante'] ?? null, $request->user()->id);

        return $this->volver($this->destino($cobro->fresh(), true), $resultado);
    }

    public function retornoVentanilla(Pago $pago): RedirectResponse
    {
        $cita = $pago->cita;

        return $this->pagos->verificar($pago)
            ? redirect()->route('citas.show', $cita)->with('status', "Pago con tarjeta recibido: la cita {$cita->codigo} quedó registrada y pagada.")
            : redirect()->to($this->destino($cita->fresh(), true))->with('error', 'Todavía no se confirmó el pago con tarjeta. Si ya se completó, se reflejará en unos minutos.');
    }

    public function canceladoVentanilla(Pago $pago): RedirectResponse
    {
        $this->pagos->cancelarEnLinea($pago);
        $cita = $pago->cita->fresh();

        return $pago->fresh()->estado_pago === Pago::PAGADO
            ? redirect()->route('citas.show', $cita)->with('status', "Pago con tarjeta recibido: la cita {$cita->codigo} quedó registrada y pagada.")
            : redirect()->to($this->destino($cita, true))->with('error', 'Se canceló el pago con tarjeta: no se realizó ningún cobro.');
    }

    public function generarQrVentanilla(Cita $cobro): RedirectResponse
    {
        $resultado = $this->pagos->iniciarEnLinea($cobro, conQr: true, enVentanilla: true);

        return isset($resultado['pago'])
            ? redirect()->route('citas.pago.qr', $resultado['pago'])
            : $this->volver($this->destino($cobro->fresh(), true), $resultado);
    }

    public function qrVentanilla(Pago $pago): View|RedirectResponse
    {
        return $this->pantallaQr($pago, true, route('citas.pago.qr', $pago), route('citas.pago.qr.cancelar', $pago), 'POST');
    }

    public function cancelarQrVentanilla(Pago $pago): RedirectResponse
    {
        $this->pagos->cancelarEnLinea($pago);
        $cita = $pago->cita->fresh();

        return $pago->fresh()->estado_pago === Pago::PAGADO
            ? redirect()->route('citas.show', $cita)->with('status', 'El paciente ya había pagado: la cita quedó pagada.')
            : redirect()->to($this->destino($cita, true))->with('status', 'Se anuló el código QR: no se realizó ningún cobro.');
    }

    // --- Páginas públicas firmadas (se abren en el celular desde el QR) ----------------

    public function simulado(Request $request, Pago $pago): View
    {
        $this->simulable($pago);
        $pago->load('cita.especialidad');

        return view('pagos.simulado', ['pago' => $pago, 'accion' => $request->fullUrl()]);
    }

    public function completarSimulado(Request $request, Pago $pago): RedirectResponse
    {
        $destino = $this->simulable($pago)->completar((string) $pago->id_transaccion, $request->input('accion') === 'pagar');
        abort_unless($destino !== null, 404);

        return redirect()->to($destino);
    }

    public function qrListo(Pago $pago): View
    {
        $pagado = $this->pagos->verificar($pago);

        return view('pagos.resultado', [
            'titulo' => $pagado ? 'Pago recibido' : 'Pago en proceso',
            'mensaje' => $pagado
                ? 'El pago de la cita ' . $pago->cita->codigo . ' quedó registrado. Ya puedes cerrar esta ventana.'
                : 'Todavía no recibimos la confirmación del pago. Si ya pagaste, se reflejará en unos minutos.',
            'exito' => $pagado,
        ]);
    }

    public function qrCancelado(Pago $pago): View
    {
        $this->pagos->cancelarEnLinea($pago);
        $pagado = $pago->fresh()->estado_pago === Pago::PAGADO;

        return view('pagos.resultado', [
            'titulo' => $pagado ? 'Pago recibido' : 'Pago cancelado',
            'mensaje' => $pagado ? 'El pago ya se había completado. Ya puedes cerrar esta ventana.' : 'No se realizó ningún cobro. Ya puedes cerrar esta ventana.',
            'exito' => $pagado,
        ]);
    }

    // --- Avisos de Stripe ----------------------------------------------------------

    public function webhookStripe(Request $request): JsonResponse
    {
        $contenido = $request->getContent();

        if (! PasarelaStripe::firmaValida($contenido, $request->header('Stripe-Signature'), config('pagos.stripe.webhook_secret'))) {
            return response()->json(['error' => 'Firma no válida'], 400);
        }

        $evento = json_decode($contenido, true);
        $sesion = $evento['data']['object'] ?? [];
        $pago = isset($sesion['id']) ? Pago::where('id_transaccion', $sesion['id'])->first() : null;

        if ($pago) {
            match ($evento['type'] ?? '') {
                'checkout.session.completed', 'checkout.session.async_payment_succeeded' => PasarelaStripe::estadoDeSesion($sesion)['estado'] === 'pagado'
                    ? $this->pagos->confirmar($pago, PasarelaStripe::estadoDeSesion($sesion)['monto'])
                    : null,
                'checkout.session.expired', 'checkout.session.async_payment_failed' => $this->pagos->marcarFallido($pago, 'La pasarela informó que el pago no se completó.'),
                default => null,
            };
        }

        // Stripe solo necesita saber que el aviso llegó.
        return response()->json(['recibido' => true]);
    }

    // --- Comunes -----------------------------------------------------------------------

    private function pantallaReserva(Cita $cobro, RegistroCita $registro, bool $personal): View|RedirectResponse
    {
        if (! $cobro->esReserva()) {
            return redirect()->route($personal ? 'citas.show' : 'mis-citas.show', $cobro);
        }
        if (! $cobro->reservaVigente()) {
            $registro->anularReservasVencidas();

            return redirect()->route($personal ? 'citas.create' : 'mis-citas.create')
                ->with('error', 'El plazo para pagar la reserva venció y el horario se liberó. Vuelve a elegir un horario.');
        }

        $cobro->load(['paciente.usuario', 'medico.usuario', 'especialidad']);

        return view($personal ? 'citas.cobro' : 'mis-citas.reserva', ['cita' => $cobro, 'pagoSimulado' => config('pagos.pasarela') !== 'stripe']);
    }

    /**
     * Pantalla con el QR. «Ya pagué, actualizar» vuelve a cargarla: si el pago se completó, va a la
     * cita (recién generada); si el QR se cerró o venció, vuelve a la pantalla de cobro.
     */
    private function pantallaQr(Pago $pago, bool $personal, string $actualizar, string $cancelar, string $metodoCancelar): View|RedirectResponse
    {
        $cita = $pago->cita;

        if ($this->pagos->verificar($pago)) {
            return redirect()->route($personal ? 'citas.show' : 'mis-citas.show', $cita)
                ->with('status', "Pago recibido: la cita {$cita->codigo} quedó registrada y pagada.");
        }

        $sesion = $this->pagos->sesionAbierta($pago->fresh());
        if (! $sesion || $sesion['vence']->isPast()) {
            return redirect()->to($this->destino($cita->fresh(), $personal))->with('error', 'El código QR ya no es válido. Genera uno nuevo si quieres pagar.');
        }

        $cita->loadMissing('especialidad');

        return view('pagos.qr', [
            'cita' => $cita,
            'pago' => $pago,
            'imagen' => self::imagenQr($sesion['url']),
            'enlace' => $sesion['url'],
            'vence' => $sesion['vence'],
            'volver' => $this->destino($cita, $personal),
            'actualizar' => $actualizar,
            'cancelar' => $cancelar,
            'metodoCancelar' => $metodoCancelar,
            'personal' => $personal,
        ]);
    }

    /** A dónde volver: la pantalla de cobro si sigue siendo una reserva; si no, la cita o la lista. */
    private function destino(Cita $cita, bool $personal): string
    {
        return match (true) {
            $cita->reservaVigente() => route($personal ? 'citas.cobro' : 'mis-citas.reserva', $cita),
            in_array($cita->estado, Cita::ESTADOS_RESERVA, true) => route($personal ? 'citas.create' : 'mis-citas.create'),
            default => route($personal ? 'citas.show' : 'mis-citas.show', $cita),
        };
    }

    /** Código QR como imagen SVG (data URI), generado en el servidor. */
    public static function imagenQr(string $contenido): string
    {
        return (new QRCode(new QROptions([
            'outputType' => QROutputInterface::MARKUP_SVG,
            'eccLevel' => EccLevel::M,
            'addQuietzone' => true,
        ])))->render($contenido);
    }

    /** @param  array{status?: string, error?: string}  $resultado */
    private function volver(string $destino, array $resultado): RedirectResponse
    {
        return redirect()->to($destino)->with(isset($resultado['error']) ? 'error' : 'status', $resultado['error'] ?? $resultado['status'] ?? '');
    }

    /** Solo sus propias citas y reservas: las de otro paciente responden como si no existieran. */
    private function propia(Request $request, ?Cita $cita): void
    {
        abort_unless($cita && (int) $cita->id_paciente === (int) $request->user()->id, 404);
    }

    private function simulable(Pago $pago): PasarelaSimulada
    {
        $pasarela = $this->pagos->pasarela();
        abort_unless($pasarela instanceof PasarelaSimulada && $pago->estado_pago === Pago::PENDIENTE
            && filled($pago->id_transaccion) && $pasarela->abierta($pago->id_transaccion), 404);

        return $pasarela;
    }
}
