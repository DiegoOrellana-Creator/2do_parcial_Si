<?php

/*
 * Pasarela de pago de las citas.
 *
 * - «simulada»: no cobra dinero real. Muestra una página de pago de prueba dentro de la clínica.
 *   Es la opción por defecto y no necesita claves.
 * - «stripe»: Stripe Checkout. El paciente paga en la página de Stripe y vuelve a la clínica.
 *   Necesita STRIPE_SECRET y, para recibir avisos aunque el paciente cierre el navegador,
 *   STRIPE_WEBHOOK_SECRET. Usa claves de prueba (sk_test_…) mientras no haya contrato de comercio.
 */
return [
    'pasarela' => env('PAGOS_PASARELA', 'simulada'),

    // Código ISO 4217 en minúsculas: «bob» = bolivianos.
    'moneda' => strtolower((string) env('PAGOS_MONEDA', 'bob')),

    // Minutos que el paciente tiene para completar el pago en Stripe (mínimo 30, máximo 1440).
    'minutos_para_pagar' => (int) env('PAGOS_MINUTOS', 30),

    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'url' => 'https://api.stripe.com/v1',
    ],
];
