<x-app-layout>
    <x-slot name="header">
        <div class="page-heading">
            <div>
                <h1 class="page-title">Especialidades</h1>
                <p class="page-subtitle">Especialidades médicas que ofrece la Clínica Santa Cruz</p>
            </div>

            @can('especialidades.crear')
                <a href="{{ route('especialidades.create') }}" class="btn btn--primary">
                    <x-admin.icon name="plus" class="icon--sm" />
                    Registrar especialidad
                </a>
            @endcan
        </div>
    </x-slot>

    <div class="page">
        <x-admin.flash />

        <x-admin.card :padding="false">
            <!-- Búsqueda y filtro: formulario GET normal -->
            <form method="GET" action="{{ route('especialidades.index') }}" class="search-form" role="search">
                <label for="q" class="sr-only">Buscar especialidades</label>
                <input id="q" name="q" type="search" value="{{ $busqueda }}" maxlength="100"
                       placeholder="Buscar por nombre" class="form-input search-form__input">

                <label for="filtro-estado" class="sr-only">Filtrar por estado</label>
                <select id="filtro-estado" name="estado" class="form-input search-form__select">
                    <option value="">Todos los estados</option>
                    @foreach (\App\Models\Especialidad::ESTADOS as $valor)
                        <option value="{{ $valor }}" @selected($estado === $valor)>{{ $valor === \App\Models\Especialidad::ACTIVA ? 'Activas' : 'Inactivas' }}</option>
                    @endforeach
                </select>

                <button type="submit" class="btn btn--primary">Buscar</button>
                @if ($busqueda !== '' || $estado !== '')
                    <a href="{{ route('especialidades.index') }}" class="btn btn--secondary">Limpiar</a>
                @endif
            </form>

            @if ($especialidades->isEmpty())
                <div class="empty-state">
                    @if ($busqueda !== '' || $estado !== '')
                        <p class="empty-state__title">No se encontraron especialidades con esos criterios.</p>
                        <p class="empty-state__text">Prueba con otro nombre o cambia el filtro de estado.</p>
                    @else
                        <p class="empty-state__title">Todavía no hay especialidades registradas.</p>
                        @can('especialidades.crear')
                            <p class="empty-state__text">Usa «Registrar especialidad» para añadir la primera.</p>
                        @endcan
                    @endif
                </div>
            @else
                <div class="table-scroll">
                    <table class="data-table responsive-table">
                        <caption class="sr-only">Listado de especialidades</caption>
                        <thead>
                            <tr>
                                <th scope="col">Especialidad</th>
                                <th scope="col">Médicos</th>
                                <th scope="col">Estado</th>
                                <th scope="col"><span class="sr-only">Acciones</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($especialidades as $especialidad)
                                <tr>
                                    <td data-label="Especialidad" class="cell-wrap">
                                        <span class="cell-strong">{{ $especialidad->nombre }}</span>
                                        @if ($especialidad->descripcion)
                                            <span class="cell-sub">{{ Str::limit($especialidad->descripcion, 90) }}</span>
                                        @endif
                                    </td>
                                    <td data-label="Médicos">{{ $especialidad->total_medicos }}</td>
                                    <td data-label="Estado">@include('especialidades.partials.estado')</td>
                                    <td class="cell-actions">
                                        <a href="{{ route('especialidades.show', $especialidad) }}" class="btn btn--secondary btn--sm">
                                            Ver<span class="sr-only"> {{ $especialidad->nombre }}</span>
                                        </a>
                                        @can('especialidades.editar')
                                            <a href="{{ route('especialidades.edit', $especialidad) }}" class="btn btn--secondary btn--sm">
                                                Editar<span class="sr-only"> {{ $especialidad->nombre }}</span>
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
                        Mostrando {{ $especialidades->firstItem() }}–{{ $especialidades->lastItem() }} de {{ $especialidades->total() }} {{ $especialidades->total() === 1 ? 'especialidad' : 'especialidades' }}
                    </p>

                    {{ $especialidades->links('pagination.clinica') }}
                </div>
            @endif
        </x-admin.card>
    </div>
</x-app-layout>
