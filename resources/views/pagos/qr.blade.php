<x-app-layout>
    <x-slot name="header">
        <div class="page-heading">
            <div>
                <a href="{{ $volver }}" class="back-link">← {{ $cita->esReserva() ? ($personal ? 'Cobrar la consulta' : 'Pagar la consulta') : 'Cita ' . $cita->codigo }}</a>
                <h1 class="page-title">Pagar con QR</h1>
                <p class="page-subtitle">Consulta de {{ $cita->especialidad->nombre }} · Bs {{ number_format((float) $pago->monto, 2, ',', '.') }}</p>
            </div>
        </div>
    </x-slot>

    <div class="page">
        <x-admin.flash />

        <x-admin.card>
            <div class="qr-pago">
                <img src="{{ $imagen }}" alt="Código QR para pagar la cita {{ $cita->codigo }}" class="qr-pago__imagen" width="240" height="240">

                <div class="qr-pago__texto">
                    <ol class="qr-pago__pasos">
                        <li>Abre la cámara de tu celular y escanea el código.</li>
                        <li>Paga Bs {{ number_format((float) $pago->monto, 2, ',', '.') }} en la página que se abre.</li>
                        <li>Cuando termines, pulsa «Ya pagué, actualizar».</li>
                    </ol>
                    <p class="field-hint">El código vence a las {{ $vence->format('H:i') }}. ¿No puedes escanearlo? <a href="{{ $enlace }}" class="link">Abrir la página de pago aquí</a>.</p>

                    <div class="form-actions">
                        <a href="{{ $actualizar }}" class="btn btn--primary">Ya pagué, actualizar</a>
                        @if ($metodoCancelar === 'POST')
                            <form method="POST" action="{{ $cancelar }}">
                                @csrf
                                <button type="submit" class="btn btn--secondary">Anular QR</button>
                            </form>
                        @else
                            <a href="{{ $cancelar }}" class="btn btn--secondary">Cancelar pago</a>
                        @endif
                    </div>
                </div>
            </div>
        </x-admin.card>
    </div>
</x-app-layout>
