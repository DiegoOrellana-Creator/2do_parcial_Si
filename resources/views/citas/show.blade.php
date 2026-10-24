@php
use Illuminate\Support\Carbon;

$paciente = $cita->paciente->usuario;
$medico = $cita->medico->usuario;
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="page-heading">
            <div>
                <a href="{{ route('citas.index') }}" class="back-link">← Gestión de citas</a>
                <h1 class="page-title">Cita {{ $cita->codigo }}</h1>
                <div class="page-heading__meta">
                    <x-admin.status-badge :status="$cita->estado" />
                    <span class="page-subtitle">{{ Carbon::parse($cita->fecha_cita)->format('d/m/Y') }} · {{ substr($cita->hora_inicio, 0, 5) }}–{{ substr($cita->hora_fin, 0, 5) }}</span>
                </div>
            </div>

            @can('citas.cancelar')
                @if ($cita->esCancelable())
                    <div class="page-heading__actions">
                        {{-- Cancelación sin JavaScript: el botón abre la ventana; se confirma con el motivo --}}
                        <x-modal name="confirmar-cancelacion" maxWidth="md" trigger-class="btn btn--danger" :show="$errors->cancelacion->isNotEmpty()">
                            <x-slot name="trigger">Cancelar cita</x-slot>

                            <form method="POST" action="{{ route('citas.cancelar', $cita) }}" class="modal__body">
                                @csrf
                                @method('PATCH')

                                <h2 class="section-title">¿Cancelar la cita {{ $cita->codigo }}?</h2>
                                <p class="section-text">
                                    La cita de {{ $paciente->nombre }} {{ $paciente->apellido }} quedará como «Cancelada» y la franja volverá a estar disponible.
                                    La cita no se borra: se conserva con su historial.
                                </p>

                                <div class="modal__field">
                                    <x-input-label for="motivo_cancelacion">Motivo de la cancelación <span class="required-mark" aria-hidden="true">*</span></x-input-label>
                                    <textarea id="motivo_cancelacion" name="motivo_cancelacion" rows="3" maxlength="1000" required class="form-input"
                                              @if ($errors->cancelacion->has('motivo_cancelacion')) aria-invalid="true" @endif>{{ old('motivo_cancelacion') }}</textarea>
                                    <x-input-error :messages="$errors->cancelacion->get('motivo_cancelacion')" />
                                </div>

                                <div class="modal__actions">
                                    <a href="{{ route('citas.show', $cita) }}" class="btn btn--secondary">Volver</a>
                                    <x-danger-button>Cancelar cita</x-danger-button>
                                </div>
                            </form>
                        </x-modal>
                    </div>
                @endif
            @endcan
        </div>
    </x-slot>

    <div class="page">
        <x-admin.flash />

        <div class="dashboard-grid dashboard-grid--halves">
            <x-admin.card title="Datos de la cita">
                <dl class="detail-list">
                    <div><dt>Paciente</dt><dd>{{ $paciente->nombre }} {{ $paciente->apellido }}{{ $cita->paciente->nro_ficha ? ' (' . $cita->paciente->nro_ficha . ')' : '' }}</dd></div>
                    <div><dt>Médico</dt><dd>{{ $medico->nombre }} {{ $medico->apellido }}</dd></div>
                    <div><dt>Especialidad</dt><dd>{{ $cita->especialidad->nombre }}</dd></div>
                    <div><dt>Fecha</dt><dd>{{ Str::ucfirst(Carbon::parse($cita->fecha_cita)->locale('es')->isoFormat('dddd, D [de] MMMM [de] YYYY')) }}</dd></div>
                    <div><dt>Hora</dt><dd>{{ substr($cita->hora_inicio, 0, 5) }}–{{ substr($cita->hora_fin, 0, 5) }}</dd></div>
                    <div><dt>Motivo</dt><dd class="pre-line">{{ filled($cita->motivo) ? $cita->motivo : 'Sin motivo indicado' }}</dd></div>
                    <div><dt>Registrada el</dt><dd>{{ Carbon::parse($cita->fecha_creacion)->format('d/m/Y H:i') }}</dd></div>
                    @if ($cita->estado === \App\Models\Cita::CANCELADA)
                        <div><dt>Cancelada el</dt><dd>{{ $cita->fecha_cancelacion ? Carbon::parse($cita->fecha_cancelacion)->format('d/m/Y H:i') : '—' }}{{ $cita->canceladoPor ? ' por ' . $cita->canceladoPor->nombre . ' ' . $cita->canceladoPor->apellido : '' }}</dd></div>
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
                            @if ($cambio->registradoPor) · {{ $cambio->registradoPor->nombre }} {{ $cambio->registradoPor->apellido }} @endif
                            @if ($cambio->motivo) · {{ $cambio->motivo }} @endif
                        </p>
                    </div>
                @empty
                    <p class="muted card__body">Sin cambios de estado registrados.</p>
                @endforelse
            </x-admin.card>
        </div>
    </div>
</x-app-layout>
