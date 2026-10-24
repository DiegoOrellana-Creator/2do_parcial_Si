<x-app-layout>
    <x-slot name="header">
        <div class="page-heading">
            <div>
                <h1 class="page-title">Pacientes</h1>
                <p class="page-subtitle">Registro de pacientes de la Clínica Santa Cruz</p>
            </div>

            @can('pacientes.crear')
                <a href="{{ route('pacientes.create') }}" class="btn btn--primary">
                    <x-admin.icon name="plus" class="icon--sm" />
                    Registrar paciente
                </a>
            @endcan
        </div>
    </x-slot>

    <div class="page">
        <x-admin.flash />

        <x-admin.card :padding="false">
            <!-- Búsqueda: formulario GET normal -->
            <form method="GET" action="{{ route('pacientes.index') }}" class="search-form" role="search">
                <label for="q" class="sr-only">Buscar pacientes</label>
                <input id="q" name="q" type="search" value="{{ $busqueda }}" maxlength="100"
                       placeholder="Buscar por nombre, CI, correo o n.º de ficha" class="form-input search-form__input">
                <button type="submit" class="btn btn--primary">Buscar</button>
                @if ($busqueda !== '')
                    <a href="{{ route('pacientes.index') }}" class="btn btn--secondary">Limpiar</a>
                @endif
            </form>

            @if ($pacientes->isEmpty())
                <div class="empty-state">
                    @if ($busqueda !== '')
                        <p class="empty-state__title">No se encontraron pacientes para «{{ $busqueda }}».</p>
                        <p class="empty-state__text">Prueba con otro nombre, CI, correo o número de ficha.</p>
                    @else
                        <p class="empty-state__title">Todavía no hay pacientes registrados.</p>
                        @can('pacientes.crear')
                            <p class="empty-state__text">Usa «Registrar paciente» para añadir el primero.</p>
                        @endcan
                    @endif
                </div>
            @else
                <div class="table-scroll">
                    <table class="data-table responsive-table">
                        <caption class="sr-only">Listado de pacientes</caption>
                        <thead>
                            <tr>
                                <th scope="col">Paciente</th>
                                <th scope="col">CI</th>
                                <th scope="col">N.º de ficha</th>
                                <th scope="col">Teléfono</th>
                                <th scope="col">Estado</th>
                                <th scope="col"><span class="sr-only">Acciones</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($pacientes as $paciente)
                                <tr>
                                    <td data-label="Paciente">
                                        <span class="cell-strong">{{ $paciente->usuario->apellido }}, {{ $paciente->usuario->nombre }}</span>
                                        <span class="cell-sub">{{ $paciente->usuario->email }}</span>
                                    </td>
                                    <td data-label="CI">{{ $paciente->usuario->ci ?? '—' }}</td>
                                    <td data-label="N.º de ficha">{{ $paciente->nro_ficha ?? '—' }}</td>
                                    <td data-label="Teléfono">{{ $paciente->usuario->telefono ?? '—' }}</td>
                                    <td data-label="Estado">
                                        @include('pacientes.partials.estado', ['paciente' => $paciente])
                                    </td>
                                    <td class="cell-actions">
                                        <a href="{{ route('pacientes.show', $paciente) }}" class="btn btn--secondary btn--sm">
                                            Ver<span class="sr-only"> a {{ $paciente->usuario->nombre }} {{ $paciente->usuario->apellido }}</span>
                                        </a>
                                        @can('pacientes.editar')
                                            <a href="{{ route('pacientes.edit', $paciente) }}" class="btn btn--secondary btn--sm">
                                                Editar<span class="sr-only"> a {{ $paciente->usuario->nombre }} {{ $paciente->usuario->apellido }}</span>
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
                        Mostrando {{ $pacientes->firstItem() }}–{{ $pacientes->lastItem() }} de {{ $pacientes->total() }} {{ $pacientes->total() === 1 ? 'paciente' : 'pacientes' }}
                    </p>

                    {{ $pacientes->links('pagination.clinica') }}
                </div>
            @endif
        </x-admin.card>
    </div>
</x-app-layout>
