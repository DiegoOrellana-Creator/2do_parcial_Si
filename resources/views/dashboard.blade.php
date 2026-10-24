@php
/*
|--------------------------------------------------------------------------
| DATOS FICTICIOS — solo para la presentación visual
|--------------------------------------------------------------------------
| Ningún dato de este bloque procede de la base de datos ni corresponde a
| pacientes o médicos reales. Reemplazar al integrar el backend.
*/
$estadisticas = [
    ['label' => 'Citas de hoy', 'value' => '24', 'icon' => 'calendar', 'trend' => '+3 respecto a ayer', 'tone' => 'positive'],
    ['label' => 'Pendientes de confirmar', 'value' => '7', 'icon' => 'clock', 'trend' => '2 en la próxima hora', 'tone' => 'neutral'],
    ['label' => 'Pacientes nuevos del mes', 'value' => '38', 'icon' => 'user-plus', 'trend' => '+12 % frente al mes anterior', 'tone' => 'positive'],
    ['label' => 'Tasa de inasistencia', 'value' => '6,4 %', 'icon' => 'exclamation-triangle', 'trend' => '−1,2 puntos frente al mes anterior', 'tone' => 'positive'],
];

$proximasCitas = [
    ['hora' => '08:30', 'paciente' => 'María Fernanda Rojas', 'medico' => 'Dr. Luis Herrera', 'especialidad' => 'Cardiología', 'estado' => 'Confirmada'],
    ['hora' => '09:00', 'paciente' => 'Jorge Antelo Suárez', 'medico' => 'Dra. Carla Méndez', 'especialidad' => 'Pediatría', 'estado' => 'Programada'],
    ['hora' => '09:30', 'paciente' => 'Lucía Vaca Pinto', 'medico' => 'Dr. Andrés Salvatierra', 'especialidad' => 'Traumatología', 'estado' => 'Confirmada'],
    ['hora' => '10:15', 'paciente' => 'Roberto Justiniano', 'medico' => 'Dra. Patricia Rivero', 'especialidad' => 'Dermatología', 'estado' => 'Programada'],
    ['hora' => '10:45', 'paciente' => 'Valeria Chávez Ortiz', 'medico' => 'Dr. Luis Herrera', 'especialidad' => 'Cardiología', 'estado' => 'Cancelada'],
    ['hora' => '11:30', 'paciente' => 'Daniel Montaño Vargas', 'medico' => 'Dra. Carla Méndez', 'especialidad' => 'Pediatría', 'estado' => 'Confirmada'],
];

$medicosDeTurno = [
    ['nombre' => 'Dr. Luis Herrera', 'especialidad' => 'Cardiología', 'horario' => '08:00 – 12:00', 'estado' => 'En consulta'],
    ['nombre' => 'Dra. Carla Méndez', 'especialidad' => 'Pediatría', 'horario' => '08:00 – 14:00', 'estado' => 'Disponible'],
    ['nombre' => 'Dr. Andrés Salvatierra', 'especialidad' => 'Traumatología', 'horario' => '09:00 – 13:00', 'estado' => 'En consulta'],
    ['nombre' => 'Dra. Patricia Rivero', 'especialidad' => 'Dermatología', 'horario' => '14:00 – 18:00', 'estado' => 'Turno de tarde'],
];

$citasPorEspecialidad = [
    ['nombre' => 'Medicina general', 'total' => 42],
    ['nombre' => 'Pediatría', 'total' => 31],
    ['nombre' => 'Cardiología', 'total' => 24],
    ['nombre' => 'Traumatología', 'total' => 18],
    ['nombre' => 'Dermatología', 'total' => 12],
];
$maximoEspecialidad = max(array_column($citasPorEspecialidad, 'total'));

$accesosRapidos = [
    ['label' => 'Nueva cita', 'route' => 'citas.create', 'icon' => 'plus'],
    ['label' => 'Registrar paciente', 'route' => 'pacientes.create', 'icon' => 'user-plus'],
    ['label' => 'Ver agenda', 'route' => 'agenda.index', 'icon' => 'calendar'],
    ['label' => 'Generar reporte', 'route' => 'reportes.index', 'icon' => 'chart-bar'],
];

$estadoMedico = [
    'Disponible' => 'status-dot--disponible',
    'En consulta' => 'status-dot--en-consulta',
    'Turno de tarde' => '',
];
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="greeting">
            <div>
                <p class="greeting__date">
                    {{ Str::ucfirst(now()->locale('es')->isoFormat('dddd, D [de] MMMM [de] YYYY')) }}
                </p>
                <h1 class="greeting__title">Hola, {{ Auth::user()->nombre }}</h1>
            </div>

            <span class="demo-notice">
                <span class="demo-notice__dot" aria-hidden="true"></span>
                Datos de demostración: no corresponden a pacientes reales
            </span>
        </div>
    </x-slot>

    <div class="page">
        <!-- Estadísticas -->
        <section aria-label="Resumen del día" class="stats-grid">
            @foreach ($estadisticas as $stat)
                <x-admin.stat-card :label="$stat['label']" :value="$stat['value']" :icon="$stat['icon']"
                                   :trend="$stat['trend']" :trend-tone="$stat['tone']" />
            @endforeach
        </section>

        <div class="dashboard-grid dashboard-grid--main">
            <!-- Próximas citas -->
            <x-admin.card title="Próximas citas" subtitle="Agenda de hoy" :padding="false" class="span-2">
                <!-- Tabla (escritorio) -->
                <div class="table-wrapper">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th scope="col">Hora</th>
                                <th scope="col">Paciente</th>
                                <th scope="col">Médico</th>
                                <th scope="col">Especialidad</th>
                                <th scope="col">Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($proximasCitas as $cita)
                                <tr>
                                    <td class="cell-time">{{ $cita['hora'] }}</td>
                                    <td class="cell-strong">{{ $cita['paciente'] }}</td>
                                    <td>{{ $cita['medico'] }}</td>
                                    <td>{{ $cita['especialidad'] }}</td>
                                    <td><x-admin.status-badge :status="$cita['estado']" /></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <!-- Lista (móvil) -->
                <ul class="appointment-list">
                    @foreach ($proximasCitas as $cita)
                        <li>
                            <span class="appointment-list__time">{{ $cita['hora'] }}</span>
                            <div class="appointment-list__body">
                                <p class="item-title">{{ $cita['paciente'] }}</p>
                                <p class="item-meta">{{ $cita['medico'] }} · {{ $cita['especialidad'] }}</p>
                            </div>
                            <x-admin.status-badge :status="$cita['estado']" />
                        </li>
                    @endforeach
                </ul>
            </x-admin.card>

            <!-- Accesos rápidos -->
            <x-admin.card title="Accesos rápidos">
                <div class="quick-actions">
                    @foreach ($accesosRapidos as $acceso)
                        @if (Route::has($acceso['route']))
                            <a href="{{ route($acceso['route']) }}" class="quick-action">
                                <x-admin.icon :name="$acceso['icon']" class="icon--lg" />
                                {{ $acceso['label'] }}
                            </a>
                        @else
                            <span aria-disabled="true" title="Disponible próximamente" class="quick-action quick-action--disabled">
                                <x-admin.icon :name="$acceso['icon']" class="icon--lg" />
                                {{ $acceso['label'] }}
                                <span class="quick-action__soon">Pronto</span>
                            </span>
                        @endif
                    @endforeach
                </div>
            </x-admin.card>
        </div>

        <div class="dashboard-grid dashboard-grid--halves">
            <!-- Médicos de turno -->
            <x-admin.card title="Médicos de turno" subtitle="Hoy" :padding="false">
                <ul class="doctor-list">
                    @foreach ($medicosDeTurno as $medico)
                        <li>
                            <span class="avatar" aria-hidden="true">
                                {{ Str::upper(Str::substr(Str::after($medico['nombre'], ' '), 0, 1)) }}
                            </span>
                            <div class="doctor-list__body">
                                <p class="item-title">{{ $medico['nombre'] }}</p>
                                <p class="item-meta">{{ $medico['especialidad'] }} · <span class="nowrap">{{ $medico['horario'] }}</span></p>
                            </div>
                            <span class="doctor-status">
                                <span class="status-dot {{ $estadoMedico[$medico['estado']] ?? '' }}" aria-hidden="true"></span>
                                {{ $medico['estado'] }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            </x-admin.card>

            <!-- Citas por especialidad -->
            <x-admin.card title="Citas por especialidad" subtitle="Este mes">
                <ul class="bar-list">
                    @foreach ($citasPorEspecialidad as $especialidad)
                        <li>
                            <div class="bar__header">
                                <span class="bar__label">{{ $especialidad['nombre'] }}</span>
                                <span class="bar__value">{{ $especialidad['total'] }}</span>
                            </div>
                            <div class="bar__track" aria-hidden="true">
                                <div class="bar__fill"
                                     style="width: {{ round($especialidad['total'] / $maximoEspecialidad * 100) }}%"></div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </x-admin.card>
        </div>
    </div>
</x-app-layout>
