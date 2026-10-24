<x-app-layout>
    <x-slot name="header">
        <div class="page-heading">
            <div>
                <h1 class="page-title">Médicos</h1>
                <p class="page-subtitle">Médicos de la Clínica Santa Cruz y las especialidades que ejercen</p>
            </div>

            @can('medicos.crear')
                <a href="{{ route('medicos.create') }}" class="btn btn--primary">
                    <x-admin.icon name="plus" class="icon--sm" />
                    Registrar médico
                </a>
            @endcan
        </div>
    </x-slot>

    <div class="page">
        <x-admin.flash />

        <x-admin.card :padding="false">
            <!-- Búsqueda y filtros: formulario GET normal -->
            <form method="GET" action="{{ route('medicos.index') }}" class="search-form" role="search">
                <label for="q" class="sr-only">Buscar médicos</label>
                <input id="q" name="q" type="search" value="{{ $busqueda }}" maxlength="100"
                       placeholder="Buscar por nombre, CI, correo, matrícula o código" class="form-input search-form__input">

                <label for="filtro-especialidad" class="sr-only">Filtrar por especialidad</label>
                <select id="filtro-especialidad" name="especialidad" class="form-input search-form__select">
                    <option value="">Todas las especialidades</option>
                    @foreach ($especialidades as $especialidad)
                        <option value="{{ $especialidad->id_especialidad }}" @selected($idEspecialidad === (int) $especialidad->id_especialidad)>
                            {{ $especialidad->nombre }}{{ $especialidad->estado !== \App\Models\Especialidad::ACTIVA ? ' (inactiva)' : '' }}
                        </option>
                    @endforeach
                </select>

                <label for="filtro-estado" class="sr-only">Filtrar por estado</label>
                <select id="filtro-estado" name="estado" class="form-input search-form__select">
                    <option value="">Todos los estados</option>
                    @foreach (\App\Models\User::ESTADOS as $valor)
                        <option value="{{ $valor }}" @selected($estado === $valor)>{{ $valor === \App\Models\User::ESTADO_ACTIVO ? 'Activos' : 'Inactivos' }}</option>
                    @endforeach
                </select>

                <button type="submit" class="btn btn--primary">Buscar</button>
                @if ($busqueda !== '' || $estado !== '' || $idEspecialidad > 0)
                    <a href="{{ route('medicos.index') }}" class="btn btn--secondary">Limpiar</a>
                @endif
            </form>

            @if ($medicos->isEmpty())
                <div class="empty-state">
                    @if ($busqueda !== '' || $estado !== '' || $idEspecialidad > 0)
                        <p class="empty-state__title">No se encontraron médicos con esos criterios.</p>
                        <p class="empty-state__text">Prueba con otro texto o cambia los filtros.</p>
                    @else
                        <p class="empty-state__title">Todavía no hay médicos registrados.</p>
                        @can('medicos.crear')
                            <p class="empty-state__text">Usa «Registrar médico» para añadir el primero.</p>
                        @endcan
                    @endif
                </div>
            @else
                <div class="table-scroll">
                    <table class="data-table responsive-table">
                        <caption class="sr-only">Listado de médicos</caption>
                        <thead>
                            <tr>
                                <th scope="col">Médico</th>
                                <th scope="col">Matrícula</th>
                                <th scope="col">Especialidades</th>
                                <th scope="col">Estado</th>
                                <th scope="col"><span class="sr-only">Acciones</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($medicos as $medico)
                                <tr>
                                    <td data-label="Médico">
                                        <span class="cell-strong">{{ $medico->usuario->apellido }}, {{ $medico->usuario->nombre }}</span>
                                        <span class="cell-sub">{{ $medico->usuario->email }}</span>
                                    </td>
                                    <td data-label="Matrícula">{{ $medico->matricula ?? '—' }}</td>
                                    <td data-label="Especialidades" class="cell-wrap">
                                        <span class="chip-list">
                                            @forelse ($medico->especialidades as $especialidad)
                                                <span @class(['chip', 'chip--inactiva' => $especialidad->estado !== \App\Models\Especialidad::ACTIVA])>{{ $especialidad->nombre }}</span>
                                            @empty
                                                <span class="cell-sub">Sin especialidades</span>
                                            @endforelse
                                        </span>
                                    </td>
                                    <td data-label="Estado">@include('medicos.partials.estado')</td>
                                    <td class="cell-actions">
                                        <a href="{{ route('medicos.show', $medico) }}" class="btn btn--secondary btn--sm">
                                            Ver<span class="sr-only"> a {{ $medico->usuario->nombre }} {{ $medico->usuario->apellido }}</span>
                                        </a>
                                        @can('medicos.editar')
                                            <a href="{{ route('medicos.edit', $medico) }}" class="btn btn--secondary btn--sm">
                                                Editar<span class="sr-only"> a {{ $medico->usuario->nombre }} {{ $medico->usuario->apellido }}</span>
                                            </a>
                                        @endcan
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="list-footer">
                    <p class="list-footer__summary">
                        Mostrando {{ $medicos->firstItem() }}–{{ $medicos->lastItem() }} de {{ $medicos->total() }} {{ $medicos->total() === 1 ? 'médico' : 'médicos' }}
                    </p>

                    {{ $medicos->links('pagination.clinica') }}
                </div>
            @endif
        </x-admin.card>
    </div>
</x-app-layout>
