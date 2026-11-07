@php
use Illuminate\Support\Carbon;

$medico = $cita->medico->usuario;
$pagado = $cita->pagos->firstWhere('estado_pago', \App\Models\Pago::PAGADO);
$pagoSimulado = config('pagos.pasarela') !== 'stripe';
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="page-heading">
            <div>
                <a href="{{ route('mis-citas.index') }}" class="back-link">← Mis citas</a>
                <h1 class="page-title">Cita {{ $cita->codigo }}</h1>
                <div class="page-heading__meta">
                    <x-admin.status-badge :status="$cita->estado" />
                    <span class="page-subtitle">{{ Carbon::parse($cita->fecha_cita)->format('d/m/Y') }} · {{ substr($cita->hora_inicio, 0, 5) }}–{{ substr($cita->hora_fin, 0, 5) }}</span>
                </div>
            </div>

            @if ($cita->esCancelable())
                <div class="page-heading__actions">
                    @unless ($paciente->bloqueado)
                        <a href="{{ route('mis-citas.reprogramar', $cita) }}" class="btn btn--secondary">Reprogramar</a>
                    @endunless

                    {{-- Cancelación sin JavaScript: el botón abre la ventana; se confirma con el motivo --}}
                    <x-modal name="confirmar-cancelacion" maxWidth="md" trigger-class="btn btn--danger" :show="$errors->cancelacion->isNotEmpty()">
                        <x-slot name="trigger">Cancelar cita</x-slot>

                        <form method="POST" action="{{ route('mis-citas.cancelar', $cita) }}" class="modal__body">
                            @csrf
                            @method('PATCH')

                            <h2 class="section-title">¿Cancelar la cita {{ $cita->codigo }}?</h2>
                            <p class="section-text">La cita quedará como «Cancelada» y ese horario volverá a estar disponible. Se conserva en tu historial.</p>
                            @if ($pagado)
                                <p class="section-text">Esta cita ya está pagada: el importe no se devuelve automáticamente. Consulta en recepción.</p>
                            @endif

                            <div class="modal__field">
                                <x-input-label for="motivo_cancelacion">Motivo de la cancelación <span class="required-mark" aria-hidden="true">*</span></x-input-label>
                                <textarea id="motivo_cancelacion" name="motivo_cancelacion" rows="3" maxlength="1000" required class="form-input"
                                          @if ($errors->cancelacion->has('motivo_cancelacion')) aria-invalid="true" @endif>{{ old('motivo_cancelacion') }}</textarea>
                                <x-input-error :messages="$errors->cancelacion->get('motivo_cancelacion')" />
                            </div>

                            <div class="modal__actions">
                                <a href="{{ route('mis-citas.show', $cita) }}" class="btn btn--secondary">Volver</a>
                                <x-danger-button>Cancelar cita</x-danger-button>
                            </div>
                        </form>
                    </x-modal>
                </div>
            @endif
        </div>
    </x-slot>

    <div class="page">
        <x-admin.flash />

        <div class="dashboard-grid dashboard-grid--halves">
            <x-admin.card title="Datos de la cita">
                <dl class="detail-list">
                    <div><dt>Estado</dt><dd>{{ $cita->estado }}</dd></div>
                    <div><dt>Médico</dt><dd>{{ $medico->nombre }} {{ $medico->apellido }}</dd></div>
                    <div><dt>Especialidad</dt><dd>{{ $cita->especialidad->nombre }}</dd></div>
                    @if ($cita->especialidad->precio !== null)
                        <div><dt>Precio de la consulta</dt><dd>Bs {{ number_format((float) $cita->especialidad->precio, 2, ',', '.') }}</dd></div>
                    @endif
                    <div><dt>Fecha</dt><dd>{{ Str::ucfirst(Carbon::parse($cita->fecha_cita)->locale('es')->isoFormat('dddd, D [de] MMMM [de] YYYY')) }}</dd></div>
                    <div><dt>Hora</dt><dd>{{ substr($cita->hora_inicio, 0, 5) }}–{{ substr($cita->hora_fin, 0, 5) }}</dd></div>
                    <div><dt>Motivo</dt><dd class="pre-line">{{ filled($cita->motivo) ? $cita->motivo : 'Sin motivo indicado' }}</dd></div>
                    <div><dt>Solicitada el</dt><dd>{{ Carbon::parse($cita->fecha_creacion)->format('d/m/Y H:i') }}</dd></div>
                    @if ($cita->estado === \App\Models\Cita::CANCELADA)
                        <div><dt>Cancelada el</dt><dd>{{ $cita->fecha_cancelacion ? Carbon::parse($cita->fecha_cancelacion)->format('d/m/Y H:i') : '—' }}</dd></div>
                        <div><dt>Motivo de cancelación</dt><dd class="pre-line">{{ $cita->motivo_cancelacion }}</dd></div>
                    @endif
                </dl>
            </x-admin.card>

            <x-admin.card title="Historial de estados" :padding="false">
                @forelse ($cita->historial as $cambio)
                    <div class="contact-item">
                        <p class="item-title">{{ $cambio->estado_anterior ? $cambio->estado_anterior . ' → ' : '' }}{{ $cambio->estado_nuevo }}</p>
                        <p class="item-meta">
                            {{ Carbon::parse($cambio->fecha_hora)->format('d/m/Y H:i') }}
                            @if ($cambio->motivo) · {{ $cambio->motivo }} @endif
                        </p>
                    </div>
                @empty
                    <p class="muted card__body">Sin cambios de estado registrados.</p>
                @endforelse
            </x-admin.card>
        </div>

        <x-admin.card title="Pago de la consulta">
            @if ($pagado)
                <dl class="detail-list">
                    <div><dt>Estado</dt><dd>Pagado</dd></div>
                    <div><dt>Importe</dt><dd>Bs {{ number_format((float) $pagado->monto, 2, ',', '.') }}</dd></div>
                    <div><dt>Método</dt><dd>{{ $pagado->metodo_de_pago ?? '—' }}</dd></div>
                    <div><dt>Fecha de pago</dt><dd>{{ $pagado->fecha_pago?->format('d/m/Y H:i') ?? '—' }}</dd></div>
                    <div><dt>N.º de comprobante</dt><dd>{{ $pagado->id_pago }}</dd></div>
                </dl>
            @elseif ($cita->admitePagoEnLinea())
                <p class="section-text">Importe a pagar: <strong>Bs {{ number_format($cita->precioCobrable(), 2, ',', '.') }}</strong>. Al pagar, tu cita queda confirmada.</p>
                <div class="form-actions">
                    <form method="POST" action="{{ route('mis-citas.pagar', $cita) }}">
                        @csrf
                        <button type="submit" class="btn btn--primary">Pagar con tarjeta</button>
                    </form>
                    <form method="POST" action="{{ route('mis-citas.pagar.qr', $cita) }}">
                        @csrf
                        <button type="submit" class="btn btn--secondary">Pagar con QR</button>
                    </form>
                </div>
                <p class="field-hint">«Pagar con QR» muestra un código para escanear con tu celular y pagar desde ahí.</p>
                <p class="field-hint">
                    @if ($pagoSimulado)
                        Modo de prueba: el pago en línea es simulado y no cobra dinero real.
                    @else
                        Pagarás en la página segura de la pasarela; la clínica no ve ni guarda los datos de tu tarjeta.
                    @endif
                    También puedes pagar en recepción.
                </p>
            @elseif (in_array($cita->estado, \App\Models\Cita::ESTADOS_CANCELABLES, true) && $cita->precioCobrable() === null)
                <p class="muted">Esta especialidad todavía no tiene precio registrado. Consulta el importe en recepción.</p>
            @else
                <p class="muted">No hay un pago registrado para esta cita.</p>
            @endif
        </x-admin.card>
    </div>
</x-app-layout>
