<x-app-layout>
    <x-slot name="header">
        <div class="page-heading">
            <div>
                <h1 class="page-title">Usuarios</h1>
                <p class="page-subtitle">Cuentas de acceso a la Clínica Santa Cruz. Las cuentas no se eliminan: se desactivan.</p>
            </div>

            <a href="{{ route('usuarios.create') }}" class="btn btn--primary">
                <x-admin.icon name="plus" class="icon--sm" />
                Crear cuenta de personal
            </a>
        </div>
    </x-slot>

    <div class="page">
        <x-admin.flash />

        <x-admin.card :padding="false">
            <!-- Filtros: formulario GET normal -->
            <form method="GET" action="{{ route('usuarios.index') }}" class="search-form" role="search">
                <div class="search-form__field search-form__field--ancho">
                    <label for="q" class="form-label">Buscar</label>
                    <input id="q" name="q" type="search" value="{{ $busqueda }}" maxlength="100" class="form-input" placeholder="Nombre, correo, usuario o CI">
                </div>
                <div class="search-form__field">
                    <label for="filtro-perfil" class="form-label">Perfil</label>
                    <select id="filtro-perfil" name="perfil" class="form-input">
                        <option value="">Todos</option>
                        @foreach ($perfiles as $valor)
                            <option value="{{ $valor }}" @selected($perfil === $valor)>{{ $valor }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="search-form__field">
                    <label for="filtro-estado" class="form-label">Estado</label>
                    <select id="filtro-estado" name="estado" class="form-input">
                        <option value="">Todos</option>
                        @foreach (\App\Models\User::ESTADOS as $valor)
                            <option value="{{ $valor }}" @selected($estado === $valor)>{{ $valor }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="search-form__field search-form__field--boton">
                    <button type="submit" class="btn btn--primary">Filtrar</button>
                    <a href="{{ route('usuarios.index') }}" class="btn btn--secondary">Limpiar</a>
                </div>
            </form>

            @if ($usuarios->isEmpty())
                <div class="empty-state">
                    <p class="empty-state__title">No hay cuentas con esos filtros.</p>
                </div>
            @else
                <div class="table-scroll">
                    <table class="data-table responsive-table">
                        <caption class="sr-only">Cuentas de acceso</caption>
                        <thead>
                            <tr>
                                <th scope="col">Nombre</th>
                                <th scope="col">Usuario y correo</th>
                                <th scope="col">Perfil</th>
                                <th scope="col">Estado</th>
                                <th scope="col"><span class="sr-only">Acciones</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($usuarios as $usuario)
                                <tr>
                                    <td data-label="Nombre" class="cell-strong">{{ $usuario->apellido }}, {{ $usuario->nombre }}</td>
                                    <td data-label="Usuario y correo">
                                        <span class="cell-strong">{{ $usuario->username }}</span>
                                        <span class="cell-sub">{{ $usuario->email }}</span>
                                    </td>
                                    <td data-label="Perfil">{{ $usuario->etiquetaPerfil() }}</td>
                                    <td data-label="Estado">
                                        <span @class(['badge', 'badge--confirmada' => $usuario->estado === 'Activo', 'badge--atendida' => $usuario->estado !== 'Activo'])>{{ $usuario->estado }}</span>
                                    </td>
                                    <td class="cell-actions">
                                        <a href="{{ route('usuarios.edit', $usuario) }}" class="btn btn--secondary btn--sm">Editar<span class="sr-only"> cuenta de {{ $usuario->username }}</span></a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="list-footer">
                    <p class="list-footer__summary">Mostrando {{ $usuarios->firstItem() }}–{{ $usuarios->lastItem() }} de {{ $usuarios->total() }} {{ $usuarios->total() === 1 ? 'cuenta' : 'cuentas' }}</p>
                    {{ $usuarios->links('pagination.clinica') }}
                </div>
            @endif
        </x-admin.card>
    </div>
</x-app-layout>
