<x-app-layout>
    <x-slot name="header">
        <div class="page-heading">
            <div>
                <h1 class="page-title">Gestión de citas</h1>
                <p class="page-subtitle">Citas registradas en la Clínica Santa Cruz</p>
            </div>

            @can('citas.crear')
                <a href="{{ route('citas.create') }}" class="btn btn--primary">
                    <x-admin.icon name="plus" class="icon--sm" />
                    Registrar cita
                </a>
            @endcan
        </div>
    </x-slot>

    <div class="page">
        <x-admin.flash />

        <x-admin.card :padding="false">
            <!-- Filtros: formulario GET normal -->
            <form method="GET" action="{{ route('citas.index') }}" class="search-form" role="search">
                <div class="search-form__field search-form__field--ancho">
                    <label for="q" class="form-label">Paciente o código</label>
                    <input id="q" name="q" type="search" value="{{ $filtros['q'] }}" maxlength="100" class="form-input" placeholder="Nombre, CI, n.º de ficha o código">
                </div>
                <div class="search-form__field">
                    <label for="desde" class="form-label">Desde</label>
                    <input id="desde" name="desde" type="date" value="{{ $filtros['desde'] }}" class="form-input">
                </div>
                <div class="search-form__field">
                    <label for="hasta" class="form-label">Hasta</label>
                    <input id="hasta" name="hasta" type="date" value="{{ $filtros['hasta'] }}" class="form-input">
                </div>
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
                    <label for="filtro-medico" class="form-label">Médico</label>
                    <select id="filtro-medico" name="medico" class="form-input">
                        <option value="">Todos</option>
                        @foreach ($medicos as $medico)
                            <option value="{{ $medico->id_medico }}" @selected($filtros['medico'] === (int) $medico->id_medico)>{{ $medico->usuario->apellido }}, {{ $medico->usuario->nombre }}</option>
                        @endforeach
                    </select>
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
                <div class="search-form__field search-form__field--boton">
                    <button type="submit" class="btn btn--primary">Filtrar</button>
                    <a href="{{ route('citas.index') }}" class="btn btn--secondary">Limpiar</a>
                </div>
            </form>

            @if ($citas->isEmpty())
                <div class="empty-state">
                    <p class="empty-state__title">No hay citas que coincidan con los filtros.</p>
                    <p class="empty-state__text">Por defecto se muestran las citas desde hoy; cambia «Desde» para ver citas anteriores.</p>
                </div>
            @else
                <div class="table-scroll">
                    <table class="data-table responsive-table">
                        <caption class="sr-only">Listado de citas</caption>
                        <thead>
                            <tr>
                                <th scope="col">Fecha y hora</th>
                                <th scope="col">Paciente</th>
                                <th scope="col">Médico</th>
                                <th scope="col">Especialidad</th>
                                <th scope="col">Estado</th>
                                <th scope="col"><span class="sr-only">Acciones</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($citas as $cita)
                                <tr>
                                    <td data-label="Fecha y hora">
                                        <span class="cell-strong">{{ \Illuminate\Support\Carbon::parse($cita->fecha_cita)->format('d/m/Y') }} · {{ substr($cita->hora_inicio, 0, 5) }}</span>
                                        <span class="cell-sub">{{ $cita->codigo }}</span>
                                    </td>
                                    <td data-label="Paciente">{{ $cita->paciente->usuario->apellido }}, {{ $cita->paciente->usuario->nombre }}</td>
                                    <td data-label="Médico">{{ $cita->medico->usuario->apellido }}, {{ $cita->medico->usuario->nombre }}</td>
                                    <td data-label="Especialidad">{{ $cita->especialidad->nombre }}</td>
                                    <td data-label="Estado"><x-admin.status-badge :status="$cita->estado" /></td>
                                    <td class="cell-actions">
                                        <a href="{{ route('citas.show', $cita) }}" class="btn btn--secondary btn--sm">Ver<span class="sr-only"> cita {{ $cita->codigo }}</span></a>
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
