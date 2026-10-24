@php
use Illuminate\Support\Carbon;

$medico = $cita->medico->usuario;
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
                    <div><dt>Fecha</dt><dd>{{ Str::ucfirst(Carbon::parse($cita->fecha_cita)->locale('es')->isoFormat('dddd, D [de] MMMM [de] YYYY')) }}</dd></div>
                    <div><dt>Hora</dt><dd>{{ substr($cita->hora_inicio, 0, 5) }}–{{ substr($cita->hora_fin, 0, 5) }}</dd></div>
                    <div><dt>Motivo</dt><dd class="pre-line">{{ filled($cita->motivo) ? $cita->motivo : 'Sin motivo indicado' }}</dd></div>
                    <div><dt>Solicitada el</dt><dd>{{ Carbon::parse($cita->fecha_creacion)->format('d/m/Y H:i') }}</dd></div>
                    @if ($cita->estado === \App\Models\Cita::CANCELADA)
                        <div><dt>Cancelada el</dt><dd>{{ $cita->fecha_cancelacion ? Carbon::parse($cita->fecha_cancelacion)->format('d/m/Y H:i') : '—' }}</dd></div>
                        <div><dt>Motivo de cancelación</dt><dd class="pre-line">{{ $cita->motivo_cancelacion }}</dd></div>
                    @endif
                </dl>
                @if (in_array($cita->estado, \App\Models\Cita::ESTADOS_CANCELABLES, true))
                    <p class="field-hint">Si no puedes asistir, comunícate con la clínica para cancelar la cita.</p>
                @endif
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
