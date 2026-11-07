<?php

namespace App\Services\Pagos;

use App\Models\Cita;
use App\Models\Pago;

/**
 * Pasarela de pago en línea. La clínica nunca recibe ni guarda datos de tarjeta: el paciente paga en
 * la página de la pasarela y la clínica solo consulta si el pago se completó.
 */
interface PasarelaPago
{
    /** Texto que se guarda en `pago.metodo_de_pago` (máx. 50). */
    public function nombre(): string;

    /** Si tiene lo necesario (claves) para cobrar. */
    public function disponible(): bool;

    /**
     * Abre una sesión de pago y devuelve su referencia (se guarda en `pago.id_transaccion`) y la
     * dirección a la que se envía al paciente.
     *
     * @return array{referencia: string, url: string}
     */
    public function iniciar(Pago $pago, Cita $cita, string $urlRetorno, string $urlCancelado): array;

    /**
     * Estado de una sesión: «pagado» con el importe cobrado, «abierto» o «cerrado» (expiró o se anuló).
     *
     * @return array{estado: 'pagado'|'abierto'|'cerrado', monto: ?float}
     */
    public function consultar(string $referencia): array;

    /** Cierra una sesión todavía abierta para que ya no se pueda pagar. */
    public function anular(string $referencia): void;
}
