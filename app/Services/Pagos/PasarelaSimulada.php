<?php

namespace App\Services\Pagos;

use App\Models\Cita;
use App\Models\Pago;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Pasarela de prueba: no cobra dinero. Su «página de pago» (pagos.simulado) es pública y firmada,
 * como la de una pasarela real, para poder abrirla también desde el QR en otro dispositivo. El estado
 * de cada sesión se guarda en la caché, igual que una pasarela lo haría en sus servidores.
 */
class PasarelaSimulada implements PasarelaPago
{
    private const DURACION_HORAS = 24;

    public function nombre(): string
    {
        return 'En línea (simulado)';
    }

    public function disponible(): bool
    {
        return true;
    }

    public function iniciar(Pago $pago, Cita $cita, string $urlRetorno, string $urlCancelado): array
    {
        $referencia = 'SIM-' . $pago->id_pago . '-' . Str::upper(Str::random(16));
        $this->guardar($referencia, ['estado' => 'abierto', 'monto' => (float) $pago->monto, 'retorno' => $urlRetorno, 'cancelado' => $urlCancelado]);

        return [
            'referencia' => $referencia,
            'url' => URL::temporarySignedRoute('pagos.simulado', now()->addHours(self::DURACION_HORAS), ['pago' => $pago->id_pago]),
        ];
    }

    public function consultar(string $referencia): array
    {
        $sesion = Cache::get($this->clave($referencia));

        return $sesion ? ['estado' => $sesion['estado'], 'monto' => $sesion['estado'] === 'pagado' ? $sesion['monto'] : null]
            : ['estado' => 'cerrado', 'monto' => null];
    }

    public function anular(string $referencia): void
    {
        $sesion = Cache::get($this->clave($referencia));

        if ($sesion && $sesion['estado'] === 'abierto') {
            $this->guardar($referencia, ['estado' => 'cerrado'] + $sesion);
        }
    }

    public function abierta(string $referencia): bool
    {
        return (Cache::get($this->clave($referencia))['estado'] ?? null) === 'abierto';
    }

    /**
     * Lo que haría el paciente en la página de pago de prueba. Devuelve la dirección a la que
     * vuelve (la de retorno si pagó, la de cancelación si no), o null si la sesión ya no está abierta.
     */
    public function completar(string $referencia, bool $pagar): ?string
    {
        $sesion = Cache::get($this->clave($referencia));

        if (! $sesion || $sesion['estado'] !== 'abierto') {
            return null;
        }

        if ($pagar) {
            $this->guardar($referencia, ['estado' => 'pagado'] + $sesion);
        }

        return $pagar ? $sesion['retorno'] : $sesion['cancelado'];
    }

    private function guardar(string $referencia, array $sesion): void
    {
        Cache::put($this->clave($referencia), $sesion, now()->addHours(self::DURACION_HORAS));
    }

    private function clave(string $referencia): string
    {
        return 'pago-simulado:' . $referencia;
    }
}
