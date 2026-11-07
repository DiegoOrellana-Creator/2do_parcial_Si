@php
use Illuminate\Support\Carbon;

$usuario = $paciente->usuario;
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="page-heading">
            <div>
                <a href="{{ route('pacientes.show', $paciente) }}" class="back-link">← {{ $usuario->nombre }} {{ $usuario->apellido }}</a>
                <h1 class="page-title">Historial de citas</h1>
                <p class="page-subtitle">
                    {{ $usuario->nombre }} {{ $usuario->apellido }}{{ $paciente->nro_ficha ? ' · Ficha ' . $paciente->nro_ficha : '' }} ·
                    {{ $paciente->inasistencia }} {{ $paciente->inasistencia === 1 ? 'inasistencia' : 'inasistencias' }} {{ $paciente->bloqueado ? '· Bloqueado' : '' }}
                </p>
            </div>
        </div>
    </x-slot>

    <div class="page">
        <x-admin.card :padding="false">
            <!-- Filtros: formulario GET normal -->
            <form method="GET" action="{{ route('pacientes.citas', $paciente) }}" class="search-form" role="search">
                <div class="search-form__field">
                    <label for="estado" class="form-label">Estado</label>
                    <select id="estado" name="estado" class="form-input">
                        <option value="">Todos</option>
                        @foreach (\App\Models\Cita::ESTADOS as $valor)
                            <option value="{{ $valor }}" @selected($filtros['estado'] === $valor)>{{ $valor }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="search-form__field">
                    <label for="desde" class="form-label">Desde</label>
                    <input id="desde" name="desde" type="date" value="{{ $filtros['desde'] }}" class="form-input">
                </div>
                <div class="search-form__field">
                    <label for="hasta" class="form-label">Hasta</label>
                    <input id="hasta" name="hasta" type="date" value="{{ $filtros['hasta'] }}" class="form-input">
                </div>
                <div class="search-form__field search-form__field--boton">
                    <button type="submit" class="btn btn--primary">Filtrar</button>
                    <a href="{{ route('pacientes.citas', $paciente) }}" class="btn btn--secondary">Limpiar</a>
                </div>
            </form>

            @if ($citas->isEmpty())
                <div class="empty-state">
                    <p class="empty-state__title">No hay citas con esos filtros.</p>
                </div>
            @else
                <div class="table-scroll">
                    <table class="data-table responsive-table">
                        <caption class="sr-only">Historial de citas del paciente, de la más reciente a la más antigua</caption>
                        <thead>
                            <tr>
                                <th scope="col">Fecha y hora</th>
                                <th scope="col">Médico</th>
                                <th scope="col">Especialidad</th>
                                <th scope="col">Motivo</th>
                                <th scope="col">Estado</th>
                                <th scope="col"><span class="sr-only">Acciones</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($citas as $cita)
                                <tr>
                                    <td data-label="Fecha y hora">
                                        <span class="cell-strong">{{ Carbon::parse($cita->fecha_cita)->format('d/m/Y') }} · {{ substr($cita->hora_inicio, 0, 5) }}–{{ substr($cita->hora_fin, 0, 5) }}</span>
                                        <span class="cell-sub">{{ $cita->codigo }}</span>
                                    </td>
                                    <td data-label="Médico">{{ $cita->medico->usuario->nombre }} {{ $cita->medico->usuario->apellido }}</td>
                                    <td data-label="Especialidad">{{ $cita->especialidad->nombre }}</td>
                                    <td data-label="Motivo" class="cell-wrap">{{ filled($cita->motivo) ? $cita->motivo : '—' }}</td>
                                    <td data-label="Estado"><x-admin.status-badge :status="$cita->estado" /></td>
                                    <td class="cell-actions">
                                        @can('citas.ver')
                                            <a href="{{ route('citas.show', $cita) }}" class="btn btn--secondary btn--sm">Ver<span class="sr-only"> cita {{ $cita->codigo }}</span></a>
                                        @endcan
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="list-footer">
                    <p class="list-footer__summary">Mostrando {{ $citas->firstItem() }}–{{ $citas->lastItem() }} de {{ $citas->total() }} {{ $citas->total() === 1 ? 'cita' : 'citas' }}</p>
                    {{ $citas->links('pagination.clinica') }}
                </div>
            @endif
        </x-admin.card>
    </div>
</x-app-layout>
