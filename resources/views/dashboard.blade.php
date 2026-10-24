@php
use Illuminate\Support\Carbon;

// Datos reales calculados en DashboardController.
$tarjetas = [
    ['label' => 'Citas de hoy', 'value' => $estadisticas['citasHoy'], 'icon' => 'calendar', 'trend' => 'Sin contar las canceladas'],
    ['label' => 'Citas pendientes', 'value' => $estadisticas['pendientes'], 'icon' => 'clock', 'trend' => 'Programadas o confirmadas desde hoy'],
    ['label' => 'Pacientes registrados', 'value' => $estadisticas['pacientes'], 'icon' => 'users', 'trend' => 'Total de fichas de paciente'],
    ['label' => 'Médicos activos', 'value' => $estadisticas['medicosActivos'], 'icon' => 'user', 'trend' => 'Con la cuenta activa'],
];

$accesosRapidos = [
    ['label' => 'Nueva cita', 'route' => 'citas.create', 'icon' => 'plus', 'can' => 'citas.crear'],
    ['label' => 'Registrar paciente', 'route' => 'pacientes.create', 'icon' => 'user-plus', 'can' => 'pacientes.crear'],
    ['label' => 'Ver agenda', 'route' => 'agenda.index', 'icon' => 'calendar', 'can' => 'agenda.ver'],
    // Reportes: Ciclo 2.
    ['label' => 'Generar reporte', 'route' => null, 'icon' => 'chart-bar'],
];

$maximoEspecialidad = max(1, (int) $porEspecialidad->max('total'));
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="greeting">
            <div>
                <p class="greeting__date">{{ Str::ucfirst($hoy->locale('es')->isoFormat('dddd, D [de] MMMM [de] YYYY')) }}</p>
                <h1 class="greeting__title">Hola, {{ Auth::user()->nombre }}</h1>
            </div>
        </div>
    </x-slot>

    <div class="page">
        <!-- Estadísticas -->
        <section aria-label="Resumen" class="stats-grid">
            @foreach ($tarjetas as $stat)
                <x-admin.stat-card :label="$stat['label']" :value="$stat['value']" :icon="$stat['icon']" :trend="$stat['trend']" />
            @endforeach
        </section>

        <div class="dashboard-grid dashboard-grid--main">
            <!-- Próximas citas -->
            <x-admin.card title="Próximas citas" subtitle="Programadas o confirmadas" :padding="false" class="span-2">
                @if ($proximas->isEmpty())
                    <div class="empty-state">
                        <p class="empty-state__title">No hay citas próximas.</p>
                        @can('citas.crear')
                            <p class="empty-state__text">Usa «Nueva cita» para registrar la primera.</p>
                        @endcan
                    </div>
                @else
                    <div class="table-scroll">
                        <table class="data-table responsive-table">
                            <caption class="sr-only">Próximas citas</caption>
                            <thead>
                                <tr>
                                    <th scope="col">Fecha y hora</th>
                                    <th scope="col">Paciente</th>
                                    <th scope="col">Médico</th>
                                    <th scope="col">Especialidad</th>
                                    <th scope="col">Estado</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($proximas as $cita)
                                    <tr>
                                        <td data-label="Fecha y hora" class="cell-time">{{ Carbon::parse($cita->fecha_cita)->format('d/m') }} · {{ substr($cita->hora_inicio, 0, 5) }}</td>
                                        <td data-label="Paciente" class="cell-strong">{{ $cita->paciente->usuario->nombre }} {{ $cita->paciente->usuario->apellido }}</td>
                                        <td data-label="Médico">{{ $cita->medico->usuario->nombre }} {{ $cita->medico->usuario->apellido }}</td>
                                        <td data-label="Especialidad">{{ $cita->especialidad->nombre }}</td>
                                        <td data-label="Estado"><x-admin.status-badge :status="$cita->estado" /></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-admin.card>

            <!-- Accesos rápidos -->
            <x-admin.card title="Accesos rápidos">
                <div class="quick-actions">
                    @foreach ($accesosRapidos as $acceso)
                        @php($existe = $acceso['route'] && Route::has($acceso['route']))
                        @php($permitido = ! isset($acceso['can']) || Gate::allows($acceso['can']))

                        @if ($existe && $permitido)
                            <a href="{{ route($acceso['route']) }}" class="quick-action">
                                <x-admin.icon :name="$acceso['icon']" class="icon--lg" />
                                {{ $acceso['label'] }}
                            </a>
                        @else
                            <span aria-disabled="true" title="{{ $existe ? 'No tienes permiso para esta acción' : 'Disponible próximamente' }}" class="quick-action quick-action--disabled">
                                <x-admin.icon :name="$acceso['icon']" class="icon--lg" />
                                {{ $acceso['label'] }}
                                <span class="quick-action__soon">{{ $existe ? 'Sin acceso' : 'Pronto' }}</span>
                            </span>
                        @endif
                    @endforeach
                </div>
            </x-admin.card>
        </div>

        <div class="dashboard-grid dashboard-grid--halves">
            <!-- Médicos que atienden hoy -->
            <x-admin.card title="Médicos que atienden hoy" subtitle="Según sus horarios de atención" :padding="false">
                @forelse ($medicosHoy as $fila)
                    <div class="contact-item">
                        <p class="item-title">{{ $fila['medico']->usuario->nombre }} {{ $fila['medico']->usuario->apellido }}</p>
                        <p class="item-meta">
                            {{ $fila['medico']->especialidades->pluck('nombre')->join(', ') ?: 'Sin especialidades' }} · <span class="nowrap">{{ $fila['bloques'] }}</span>
                        </p>
                    </div>
                @empty
                    <p class="muted card__body">Ningún médico activo tiene horario de atención hoy.</p>
                @endforelse
            </x-admin.card>

            <!-- Citas por especialidad -->
            <x-admin.card title="Citas por especialidad" subtitle="Este mes, sin contar las canceladas">
                @if ($porEspecialidad->isEmpty())
                    <p class="muted">No hay citas registradas este mes.</p>
                @else
                    <ul class="bar-list">
                        @foreach ($porEspecialidad as $fila)
                            <li>
                                <div class="bar__header">
                                    <span class="bar__label">{{ $fila->nombre }}</span>
                                    <span class="bar__value">{{ $fila->total }}</span>
                                </div>
                                <div class="bar__track" aria-hidden="true">
                                    <div class="bar__fill" style="width: {{ round($fila->total / $maximoEspecialidad * 100) }}%"></div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-admin.card>
        </div>
    </div>
</x-app-layout>
