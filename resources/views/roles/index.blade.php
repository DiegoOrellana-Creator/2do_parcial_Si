<x-app-layout>
    <x-slot name="header">
        <div class="page-heading">
            <div>
                <h1 class="page-title">Roles y permisos</h1>
                <p class="page-subtitle">Qué puede hacer cada rol. Los roles personalizados se asignan a las cuentas en Usuarios.</p>
            </div>

            <a href="{{ route('roles.create') }}" class="btn btn--primary">
                <x-admin.icon name="plus" class="icon--sm" />
                Crear rol
            </a>
        </div>
    </x-slot>

    <div class="page">
        <x-admin.flash />

        <x-admin.card :padding="false">
            <div class="table-scroll">
                <table class="data-table responsive-table">
                    <caption class="sr-only">Roles</caption>
                    <thead>
                        <tr>
                            <th scope="col">Rol</th>
                            <th scope="col">Tipo</th>
                            <th scope="col">Permisos</th>
                            <th scope="col">Cuentas activas</th>
                            <th scope="col"><span class="sr-only">Acciones</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($roles as $rol)
                            <tr>
                                <td data-label="Rol" class="cell-wrap">
                                    <span class="cell-strong">{{ $rol->nombre }}</span>
                                    @if ($rol->descripcion)
                                        <span class="cell-sub">{{ $rol->descripcion }}</span>
                                    @endif
                                </td>
                                <td data-label="Tipo">{{ $rol->esBase() ? 'Del sistema' : 'Personalizado' }}</td>
                                <td data-label="Permisos">{{ $rol->total_permisos }} de {{ $totalPermisos }}</td>
                                <td data-label="Cuentas activas">{{ $rol->total_usuarios ?? 0 }}</td>
                                <td class="cell-actions">
                                    <a href="{{ route('roles.edit', $rol) }}" class="btn btn--secondary btn--sm">{{ $rol->permisosEditables() ? 'Editar' : 'Ver' }}<span class="sr-only"> rol {{ $rol->nombre }}</span></a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-admin.card>
    </div>
</x-app-layout>
