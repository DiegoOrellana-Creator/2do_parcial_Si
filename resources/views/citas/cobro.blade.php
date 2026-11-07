<x-app-layout>
    <x-slot name="header">
        <div class="page-heading">
            <div>
                <a href="{{ route('citas.index') }}" class="back-link">← Gestión de citas</a>
                <h1 class="page-title">Cobrar la consulta</h1>
                <p class="page-subtitle">3. La cita se genera al registrar el cobro</p>
            </div>
        </div>
    </x-slot>

    <div class="page">
        <x-admin.flash />

        <div class="notice notice--info" role="status">
            Horario reservado hasta las <strong>{{ $cita->venceReserva()->format('H:i') }}</strong>. Si no se cobra antes, se libera.
        </div>

        <div class="dashboard-grid dashboard-grid--halves">
            <x-admin.card title="Horario reservado">
                @include('citas.partials.resumen-reserva', ['mostrarPaciente' => true])
            </x-admin.card>

            <x-admin.card title="Cobro">
                <form method="POST" action="{{ route('citas.pago', $cita) }}" class="patient-form">
                    @csrf
                    <div class="modal__field">
                        <x-input-label for="metodo">Método de pago <span class="required-mark" aria-hidden="true">*</span></x-input-label>
                        <select id="metodo" name="metodo" required class="form-input" @if ($errors->pago->has('metodo')) aria-invalid="true" @endif>
                            @foreach (\App\Services\Pagos\RegistroPago::METODOS_VENTANILLA as $metodo)
                                <option value="{{ $metodo }}" @selected(old('metodo') === $metodo)>{{ $metodo }}</option>
                            @endforeach
                            <option value="{{ \App\Services\Pagos\RegistroPago::TARJETA }}" @selected(old('metodo') === \App\Services\Pagos\RegistroPago::TARJETA)>Tarjeta (abre la pasarela de pago)</option>
                        </select>
                        <x-input-error :messages="$errors->pago->get('metodo')" />
                    </div>
                    <div class="modal__field">
                        <x-input-label for="comprobante" value="N.º de comprobante (opcional, para efectivo o QR bancario)" />
                        <x-text-input id="comprobante" name="comprobante" type="text" maxlength="100" :value="old('comprobante')" :aria-invalid="$errors->pago->has('comprobante') ? 'true' : null" />
                        <x-input-error :messages="$errors->pago->get('comprobante')" />
                    </div>
                    <div class="form-actions">
                        <x-primary-button>Cobrar Bs {{ number_format((float) $cita->precioCobrable(), 2, ',', '.') }} y generar la cita</x-primary-button>
                    </div>
                </form>

                <div class="form-actions">
                    <form method="POST" action="{{ route('citas.pago.qr.generar', $cita) }}">
                        @csrf
                        <button type="submit" class="btn btn--secondary">Mostrar QR de pago</button>
                    </form>
                    <form method="POST" action="{{ route('citas.reserva.anular', $cita) }}">
                        @csrf
                        <button type="submit" class="btn btn--secondary">Anular reserva</button>
                    </form>
                </div>
                <p class="field-hint">Con el QR, el paciente paga con tarjeta desde su celular y la cita se genera sola.</p>
            </x-admin.card>
        </div>
    </div>
</x-app-layout>
