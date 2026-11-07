@php
use Illuminate\Support\Carbon;

$periodo = Carbon::parse($filtros['desde'])->format('d/m/Y') . ' – ' . Carbon::parse($filtros['hasta'])->format('d/m/Y');
$maximoEstado = max(1, $resumen->max());
$bs = fn (float $monto) => 'Bs ' . number_format($monto, 2, ',', '.');
$porcentaje = fn (int $parte, int $total) => $total > 0 ? round($parte / $total * 100) . ' %' : '—';
$consulta = array_filter($filtros);
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="page-heading">
            <div>
                <h1 class="page-title">Reportes</h1>
                <p class="page-subtitle">Resumen de citas e ingresos del periodo {{ $periodo }}.</p>
            </div>

            @unless ($invertido)
                <a href="{{ route('reportes.exportar', $consulta) }}" class="btn btn--secondary">
                    <x-admin.icon name="document-text" class="icon--sm" />
                    Exportar citas (CSV)
                </a>
            @endunless
        </div>
    </x-slot>

    <div class="page">
        <x-admin.card :padding="false">
            <!-- Filtros: formulario GET normal -->
            <form method="GET" action="{{ route('reportes.index') }}" class="search-form" role="search" aria-label="Filtros del reporte">
                <div class="search-form__field">
                    <label for="desde" class="form-label">Desde</label>
                    <input id="desde" name="desde" type="date" value="{{ $filtros['desde'] }}" class="form-input">
                </div>
                <div class="search-form__field">
                    <label for="hasta" class="form-label">Hasta</label>
                    <input id="hasta" name="hasta" type="date" value="{{ $filtros['hasta'] }}" class="form-input">
                </div>
                <div class="search-form__field">
                    <label for="filtro-especialidad" class="form-label">Especialidad</label>
                    <select id="filtro-especialidad" name="especialidad" class="form-input">
                        <option value="">Todas</option>
                        @foreach ($especialidades as $especialidad)
                            <option value="{{ $especialidad->id_especialidad }}" @selected($filtros['especialidad'] === (int) $especialidad->id_especialidad)>{{ $especialidad->nombre }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="search-form__field">
                    <label for="filtro-medico" class="form-label">Médico</label>
                    <select id="filtro-medico" name="medico" class="form-input">
                        <option value="">Todos</option>
                        @foreach ($medicos as $medico)
                            <option value="{{ $medico->id_medico }}" @selected($filtros['medico'] === (int) $medico->id_medico)>{{ $medico->apellido }}, {{ $medico->nombre }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="search-form__field search-form__field--boton">
                    <button type="submit" class="btn btn--primary">Generar</button>
                    <a href="{{ route('reportes.index') }}" class="btn btn--secondary">Mes actual</a>
                </div>
            </form>

            @if ($invertido)
                <div class="card__body">
                    <div class="notice notice--error" role="alert">La fecha «Desde» es posterior a «Hasta»: no puede haber resultados.</div>
                </div>
            @endif
        </x-admin.card>

        <!-- Indicadores -->
        <section aria-label="Indicadores del periodo" class="stats-grid">
            <x-admin.stat-card label="Citas del periodo" :value="$total" icon="calendar" trend="Todas, incluidas las canceladas" />
            <x-admin.stat-card label="Atendidas" :value="$resumen['Atendida']" icon="clipboard" :trend="$porcentaje($resumen['Atendida'], $total) . ' del total'" />
            <x-admin.stat-card label="Tasa de asistencia" :value="$tasaAsistencia === null ? '—' : $tasaAsistencia . ' %'" icon="users"
                               :trend="$resumen['No asistió'] . ' ' . ($resumen['No asistió'] === 1 ? 'inasistencia registrada' : 'inasistencias registradas')" />
            <x-admin.stat-card label="Ingresos cobrados" :value="$bs($pagos['total'])" icon="chart-bar"
                               :trend="$pagos['cantidad'] . ' ' . ($pagos['cantidad'] === 1 ? 'pago' : 'pagos') . ' con estado «Pagado»'" />
        </section>

        <div class="dashboard-grid dashboard-grid--halves">
            <x-admin.card title="Citas por estado" :subtitle="$periodo">
                @if ($total === 0)
                    <p class="muted">No hay citas en el periodo seleccionado.</p>
                @else
                    <ul class="bar-list">
                        @foreach ($resumen as $estado => $cantidad)
                            <li>
                                <div class="bar__header">
                                    <span class="bar__label">{{ $estado }}</span>
                                    <span class="bar__value">{{ $cantidad }} <span class="muted">({{ $porcentaje($cantidad, $total) }})</span></span>
                                </div>
                                <div class="bar__track" aria-hidden="true">
                                    <div class="bar__fill" style="width: {{ round($cantidad / $maximoEstado * 100) }}%"></div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-admin.card>

            <x-admin.card title="Pacientes con más inasistencias" subtitle="En el periodo · se bloquean al llegar a 3" :padding="false">
                @if ($inasistentes->isEmpty())
                    <p class="muted card__body">No hay inasistencias en el periodo.</p>
                @else
                    <div class="table-scroll">
                        <table class="data-table data-table--compacta responsive-table">
                            <caption class="sr-only">Pacientes con más inasistencias en el periodo</caption>
                            <thead>
                                <tr>
                                    <th scope="col">Paciente</th>
                                    <th scope="col">Periodo</th>
                                    <th scope="col">Acumuladas</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($inasistentes as $fila)
                                    <tr>
                                        <td data-label="Paciente">
                                            <span class="cell-strong">{{ $fila->nombre }} {{ $fila->apellido }}</span>
                                            <span class="cell-sub">{{ $fila->nro_ficha }} · {{ $fila->bloqueado ? 'Bloqueado' : 'Habilitado' }}</span>
                                        </td>
                                        <td data-label="Periodo">{{ $fila->total }}</td>
                                        <td data-label="Acumuladas">{{ $fila->inasistencia }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
                <p class="muted card__body">Pacientes bloqueados ahora: {{ $bloqueados }}.</p>
            </x-admin.card>
        </div>

        @foreach (['Citas por especialidad' => [$porEspecialidad, 'Especialidad'], 'Citas por médico' => [$porMedico, 'Médico']] as $titulo => [$filas, $columna])
            <x-admin.card :title="$titulo" :subtitle="$periodo" :padding="false">
                @if ($filas->isEmpty())
                    <p class="muted card__body">No hay citas en el periodo seleccionado.</p>
                @else
                    <div class="table-scroll">
                        <table class="data-table data-table--compacta responsive-table">
                            <caption class="sr-only">{{ $titulo }}</caption>
                            <thead>
                                <tr>
                                    <th scope="col">{{ $columna }}</th>
                                    <th scope="col">Citas</th>
                                    <th scope="col">Atendidas</th>
                                    <th scope="col">Canceladas</th>
                                    <th scope="col">Inasistencias</th>
                                    <th scope="col">Asistencia</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($filas as $fila)
                                    <tr>
                                        <td data-label="{{ $columna }}" class="cell-strong">{{ $fila->nombre }}</td>
                                        <td data-label="Citas">{{ $fila->total }}</td>
                                        <td data-label="Atendidas">{{ (int) $fila->atendidas }}</td>
                                        <td data-label="Canceladas">{{ (int) $fila->canceladas }}</td>
                                        <td data-label="Inasistencias">{{ (int) $fila->inasistencias }}</td>
                                        <td data-label="Asistencia">{{ $porcentaje((int) $fila->atendidas, (int) $fila->atendidas + (int) $fila->inasistencias) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-admin.card>
        @endforeach

        <p class="muted">Las citas se cuentan por su fecha y los ingresos por la fecha del pago. La asistencia compara las citas atendidas con las atendidas más las inasistencias.</p>
    </div>
</x-app-layout>
