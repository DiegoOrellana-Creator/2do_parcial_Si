<?php

namespace App\Services\Pagos;

use App\Models\Cita;
use App\Models\Pago;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Stripe Checkout por su API REST (sin SDK). El paciente paga en checkout.stripe.com; la clínica
 * no ve ni guarda datos de tarjeta. Clave en STRIPE_SECRET (config/pagos.php).
 */
class PasarelaStripe implements PasarelaPago
{
    /** Tolerancia de la firma de los avisos (webhooks), en segundos. */
    private const TOLERANCIA_FIRMA = 300;

    public function nombre(): string
    {
        return 'Tarjeta en línea (Stripe)';
    }

    public function disponible(): bool
    {
        return filled(config('pagos.stripe.secret'));
    }

    public function iniciar(Pago $pago, Cita $cita, string $urlRetorno, string $urlCancelado): array
    {
        $cita->loadMissing(['especialidad', 'paciente.usuario']);
        $minutos = min(1440, max(30, (int) config('pagos.minutos_para_pagar', 30)));

        $respuesta = $this->cliente()->asForm()->post('/checkout/sessions', [
            'mode' => 'payment',
            'success_url' => $urlRetorno,
            'cancel_url' => $urlCancelado,
            'client_reference_id' => (string) $pago->id_pago,
            'customer_email' => $cita->paciente?->usuario?->email,
            // Margen de un minuto: Stripe rechaza una expiración menor a 30 minutos.
            'expires_at' => now()->addMinutes($minutos)->addMinute()->getTimestamp(),
            'metadata' => ['id_pago' => $pago->id_pago, 'cita' => $cita->codigo],
            'payment_intent_data' => ['metadata' => ['id_pago' => $pago->id_pago, 'cita' => $cita->codigo]],
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => config('pagos.moneda', 'bob'),
                    'unit_amount' => (int) round((float) $pago->monto * 100),
                    'product_data' => [
                        'name' => 'Consulta de ' . $cita->especialidad->nombre,
                        'description' => "Cita {$cita->codigo} · Clínica Santa Cruz",
                    ],
                ],
            ]],
        ]);

        if (! $respuesta->successful() || ! $respuesta->json('id') || ! $respuesta->json('url')) {
            throw new RuntimeException('Stripe no abrió la sesión de pago: ' . ($respuesta->json('error.message') ?? 'HTTP ' . $respuesta->status()));
        }

        return ['referencia' => $respuesta->json('id'), 'url' => $respuesta->json('url')];
    }

    public function consultar(string $referencia): array
    {
        $respuesta = $this->cliente()->get('/checkout/sessions/' . rawurlencode($referencia));

        if (! $respuesta->successful()) {
            throw new RuntimeException('No se pudo consultar el pago en Stripe: HTTP ' . $respuesta->status());
        }

        return self::estadoDeSesion($respuesta->json());
    }

    public function anular(string $referencia): void
    {
        // Si ya expiró o se pagó, Stripe responde con error: no hay nada que cerrar.
        $this->cliente()->asForm()->post('/checkout/sessions/' . rawurlencode($referencia) . '/expire');
    }

    /**
     * @param  array<string, mixed>  $sesion  objeto «checkout.session» de Stripe
     * @return array{estado: 'pagado'|'abierto'|'cerrado', monto: ?float}
     */
    public static function estadoDeSesion(array $sesion): array
    {
        if (($sesion['payment_status'] ?? null) === 'paid') {
            return ['estado' => 'pagado', 'monto' => isset($sesion['amount_total']) ? $sesion['amount_total'] / 100 : null];
        }

        return ['estado' => ($sesion['status'] ?? null) === 'open' ? 'abierto' : 'cerrado', 'monto' => null];
    }

    /**
     * Comprueba la cabecera Stripe-Signature («t=…,v1=…») de un aviso con STRIPE_WEBHOOK_SECRET.
     */
    public static function firmaValida(string $contenido, ?string $cabecera, ?string $secreto, ?int $ahora = null): bool
    {
        if (blank($cabecera) || blank($secreto)) {
            return false;
        }

        $marca = null;
        $firmas = [];
        foreach (explode(',', $cabecera) as $parte) {
            [$clave, $valor] = array_pad(explode('=', trim($parte), 2), 2, '');
            if ($clave === 't') {
                $marca = $valor;
            } elseif ($clave === 'v1') {
                $firmas[] = $valor;
            }
        }

        if (! ctype_digit((string) $marca) || abs(($ahora ?? time()) - (int) $marca) > self::TOLERANCIA_FIRMA) {
            return false;
        }

        $esperada = hash_hmac('sha256', $marca . '.' . $contenido, $secreto);

        foreach ($firmas as $firma) {
            if (hash_equals($esperada, $firma)) {
                return true;
            }
        }

        return false;
    }

    private function cliente(): PendingRequest
    {
        return Http::baseUrl(config('pagos.stripe.url'))
            ->withToken((string) config('pagos.stripe.secret'))
            ->acceptJson()
            ->timeout(15);
    }
}
