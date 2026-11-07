@php
use Illuminate\Support\Carbon;

$paciente = $cita->paciente->usuario;
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="page-heading">
            <div>
                <a href="{{ route('consultas.index') }}" class="back-link">← Mis citas</a>
                <h1 class="page-title">Cita {{ $cita->codigo }}</h1>
                <div class="page-heading__meta">
                    <x-admin.status-badge :status="$cita->estado" />
                    <span class="page-subtitle">{{ Carbon::parse($cita->fecha_cita)->format('d/m/Y') }} · {{ substr($cita->hora_inicio, 0, 5) }}–{{ substr($cita->hora_fin, 0, 5) }}</span>
                </div>
            </div>

            @if ($cita->puedeRegistrarAsistencia())
                <div class="page-heading__actions">
                    <form method="POST" action="{{ route('consultas.atendida', $cita) }}">
                        @csrf
                        @method('PATCH')
                        <button type="submit" class="btn btn--primary">Marcar atendida</button>
                    </form>

                    {{-- Sin JavaScript: el botón abre la ventana; la inasistencia se registra al confirmar --}}
                    <x-modal name="confirmar-inasistencia" maxWidth="md" trigger-class="btn btn--danger">
                        <x-slot name="trigger">No asistió</x-slot>

                        <form method="POST" action="{{ route('consultas.inasistencia', $cita) }}" class="modal__body">
                            @csrf
                            @method('PATCH')

                            <h2 class="section-title">¿Registrar la inasistencia?</h2>
                            <p class="section-text">
                                {{ $paciente->nombre }} {{ $paciente->apellido }} tiene {{ $cita->paciente->inasistencia }}
                                {{ $cita->paciente->inasistencia === 1 ? 'inasistencia' : 'inasistencias' }}.
                                Al llegar a {{ \App\Services\AsistenciaCita::INASISTENCIAS_PARA_BLOQUEO }} queda bloqueado y solo el administrador puede desbloquearlo.
                            </p>

                            <div class="modal__actions">
                                <a href="{{ route('consultas.show', $cita) }}" class="btn btn--secondary">Volver</a>
                                <x-danger-button>Registrar inasistencia</x-danger-button>
                            </div>
                        </form>
                    </x-modal>
                </div>
            @elseif (in_array($cita->estado, \App\Models\Cita::ESTADOS_CANCELABLES, true))
                <p class="field-hint">La asistencia se registra cuando llegue la hora de la cita.</p>
            @endif
        </div>
    </x-slot>

    <div class="page">
        <x-admin.flash />

        <div class="dashboard-grid dashboard-grid--halves">
            <x-admin.card title="Datos de la cita">
                <dl class="detail-list">
                    <div><dt>Paciente</dt><dd>{{ $paciente->nombre }} {{ $paciente->apellido }}{{ $cita->paciente->nro_ficha ? ' (' . $cita->paciente->nro_ficha . ')' : '' }}</dd></div>
                    <div><dt>Especialidad</dt><dd>{{ $cita->especialidad->nombre }}</dd></div>
                    <div><dt>Fecha</dt><dd>{{ Str::ucfirst(Carbon::parse($cita->fecha_cita)->locale('es')->isoFormat('dddd, D [de] MMMM [de] YYYY')) }}</dd></div>
                    <div><dt>Hora</dt><dd>{{ substr($cita->hora_inicio, 0, 5) }}–{{ substr($cita->hora_fin, 0, 5) }}</dd></div>
                    <div><dt>Motivo</dt><dd class="pre-line">{{ filled($cita->motivo) ? $cita->motivo : 'Sin motivo indicado' }}</dd></div>
                    @if ($cita->estado === \App\Models\Cita::CANCELADA)
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
    </div>
</x-app-layout>
