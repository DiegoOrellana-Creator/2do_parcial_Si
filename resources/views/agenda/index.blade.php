@php
use App\Services\Disponibilidad;

$parametros = fn ($fecha) => array_filter(['medico' => $perfil === \App\Models\User::PERFIL_PERSONAL ? $medico?->id_medico : null, 'fecha' => $fecha->toDateString()]);
$etiquetas = [
    Disponibilidad::LIBRE => 'Libre',
    Disponibilidad::OCUPADA => 'Ocupada',
    Disponibilidad::BLOQUEADA => 'Bloqueada',
    Disponibilidad::PASADA => 'Pasada',
];
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="page-heading">
            <div>
                <h1 class="page-title">{{ $perfil === \App\Models\User::PERFIL_MEDICO ? 'Mi agenda' : 'Agenda médica' }}</h1>
                <p class="page-subtitle">
                    {{ Str::ucfirst($fecha->locale('es')->isoFormat('dddd, D [de] MMMM [de] YYYY')) }}
                    @if ($medico) · {{ $medico->usuario->nombre }} {{ $medico->usuario->apellido }} @endif
                </p>
            </div>

            @if ($medico)
                <nav class="page-heading__actions" aria-label="Cambiar de día">
                    <a href="{{ route('agenda.index', $parametros($fecha->subDay())) }}" class="btn btn--secondary btn--sm">← Día anterior</a>
                    <a href="{{ route('agenda.index', $parametros(\Carbon\CarbonImmutable::today())) }}" class="btn btn--secondary btn--sm">Hoy</a>
                    <a href="{{ route('agenda.index', $parametros($fecha->addDay())) }}" class="btn btn--secondary btn--sm">Día siguiente →</a>
                </nav>
            @endif
        </div>
    </x-slot>

    <div class="page">
        <x-admin.card :padding="false">
            <form method="GET" action="{{ route('agenda.index') }}" class="search-form">
                @if ($perfil === \App\Models\User::PERFIL_PERSONAL)
                    <div class="search-form__field search-form__field--ancho">
                        <label for="medico" class="form-label">Médico</label>
                        <select id="medico" name="medico" class="form-input">
                            @foreach ($medicos as $opcion)
                                <option value="{{ $opcion->id_medico }}" @selected($medico && (int) $medico->id_medico === (int) $opcion->id_medico)>
                                    {{ $opcion->usuario->apellido }}, {{ $opcion->usuario->nombre }}{{ $opcion->usuario->estado !== \App\Models\User::ESTADO_ACTIVO ? ' (inactivo)' : '' }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <div class="search-form__field">
                    <label for="fecha" class="form-label">Fecha</label>
                    <input id="fecha" name="fecha" type="date" value="{{ $fecha->toDateString() }}" class="form-input">
                </div>
                <div class="search-form__field search-form__field--boton">
                    <button type="submit" class="btn btn--primary">Ver agenda</button>
                </div>
            </form>
        </x-admin.card>

        @if (! $medico)
            <x-admin.card>
                <div class="empty-state">
                    <p class="empty-state__title">No hay médicos registrados.</p>
                    <p class="empty-state__text">Regístralos en el módulo Médicos para consultar su agenda.</p>
                </div>
            </x-admin.card>
        @else
            <div class="dashboard-grid dashboard-grid--main">
                <x-admin.card title="Franjas del día" :subtitle="$horarios->isEmpty() ? 'Sin horario de atención este día' : $horarios->map(fn ($h) => \App\Models\HorarioAtencion::hhmm($h->hora_inicio) . '–' . \App\Models\HorarioAtencion::hhmm($h->hora_final))->join(' · ')" :padding="false" class="span-2">
                    @if ($franjas->isEmpty())
                        <div class="empty-state">
                            <p class="empty-state__title">No hay franjas de atención este día.</p>
                            <p class="empty-state__text">Las franjas salen de los horarios de atención del médico.</p>
                        </div>
                    @else
                        <div class="table-scroll">
                            <table class="data-table responsive-table">
                                <caption class="sr-only">Franjas de la agenda</caption>
                                <thead>
                                    <tr>
                                        <th scope="col">Hora</th>
                                        <th scope="col">Estado</th>
                                        <th scope="col">Detalle</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($franjas as $franja)
                                        <tr>
                                            <td data-label="Hora" class="cell-time">{{ $franja['inicio'] }}–{{ $franja['fin'] }}</td>
                                            <td data-label="Estado"><span class="slot slot--{{ $franja['estado'] }}">{{ $etiquetas[$franja['estado']] }}</span></td>
                                            <td data-label="Detalle" class="cell-wrap">
                                                @if ($franja['cita'])
                                                    {{ $franja['cita']->paciente_nombre }} {{ $franja['cita']->paciente_apellido }} · {{ $franja['cita']->codigo }} ({{ $franja['cita']->estado }})
                                                @elseif ($franja['bloqueo'])
                                                    {{ $franja['bloqueo']->motivo ?: 'Agenda bloqueada' }}
                                                @else
                                                    —
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </x-admin.card>

                <x-admin.card title="Citas del día" :subtitle="$citas->count() . ' ' . ($citas->count() === 1 ? 'cita' : 'citas') . ', incluidas las canceladas'" :padding="false">
                    @forelse ($citas as $cita)
                        <div class="contact-item">
                            <p class="item-title">{{ substr($cita->hora_inicio, 0, 5) }} · {{ $cita->paciente->usuario->nombre }} {{ $cita->paciente->usuario->apellido }}</p>
                            <p class="item-meta">{{ $cita->especialidad->nombre }} · <x-admin.status-badge :status="$cita->estado" /></p>
                            @if ($perfil === \App\Models\User::PERFIL_PERSONAL)
                                @can('citas.ver')
                                    <a href="{{ route('citas.show', $cita) }}" class="link link--small">Ver cita {{ $cita->codigo }}</a>
                                @endcan
                            @endif
                        </div>
                    @empty
                        <p class="muted card__body">No hay citas registradas este día.</p>
                    @endforelse
                </x-admin.card>
            </div>
        @endif
    </div>
</x-app-layout>
