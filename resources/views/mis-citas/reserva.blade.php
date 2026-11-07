<x-app-layout>
    <x-slot name="header">
        <div class="page-heading">
            <div>
                <a href="{{ route('mis-citas.index') }}" class="back-link">← Mis citas</a>
                <h1 class="page-title">Pagar la consulta</h1>
                <p class="page-subtitle">Tu cita se genera cuando completes el pago</p>
            </div>
        </div>
    </x-slot>

    <div class="page">
        <x-admin.flash />

        <div class="notice notice--info" role="status">
            Reservamos este horario para ti hasta las <strong>{{ $cita->venceReserva()->format('H:i') }}</strong>.
            Si no pagas antes, el horario se libera.
        </div>

        <x-admin.card title="Horario reservado">
            @include('citas.partials.resumen-reserva')

            <div class="form-actions">
                <form method="POST" action="{{ route('mis-citas.pagar', $cita) }}">
                    @csrf
                    <button type="submit" class="btn btn--primary">Pagar con tarjeta</button>
                </form>
                <form method="POST" action="{{ route('mis-citas.pagar.qr', $cita) }}">
                    @csrf
                    <button type="submit" class="btn btn--secondary">Pagar con QR</button>
                </form>
                <form method="POST" action="{{ route('mis-citas.reserva.anular', $cita) }}">
                    @csrf
                    <button type="submit" class="btn btn--secondary">Cancelar solicitud</button>
                </form>
            </div>
            <p class="field-hint">
                «Pagar con QR» muestra un código para escanear con tu celular y pagar desde ahí.
                @if ($pagoSimulado)
                    Modo de prueba: el pago es simulado y no cobra dinero real.
                @else
                    Pagarás en la página segura de la pasarela; la clínica no ve ni guarda los datos de tu tarjeta.
                @endif
            </p>
        </x-admin.card>
    </div>
</x-app-layout>
