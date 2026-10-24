@php
// Nombres de ruta previstos para cada módulo. Mientras una ruta no exista,
// su enlace aparece desactivado con la etiqueta «Pronto».
$menu = [
    'Principal' => [
        ['label' => 'Dashboard', 'route' => 'dashboard', 'icon' => 'home'],
        ['label' => 'Agenda', 'route' => 'agenda.index', 'icon' => 'calendar'],
    ],
    'Gestión clínica' => [
        ['label' => 'Citas', 'route' => 'citas.index', 'icon' => 'clipboard'],
        ['label' => 'Pacientes', 'route' => 'pacientes.index', 'icon' => 'users'],
        ['label' => 'Médicos', 'route' => 'medicos.index', 'icon' => 'user'],
        ['label' => 'Especialidades', 'route' => 'especialidades.index', 'icon' => 'tag'],
        ['label' => 'Horarios', 'route' => 'horarios.index', 'icon' => 'clock'],
    ],
    'Análisis' => [
        ['label' => 'Reportes', 'route' => 'reportes.index', 'icon' => 'chart-bar'],
    ],
    'Administración' => [
        ['label' => 'Usuarios', 'route' => 'usuarios.index', 'icon' => 'user-circle'],
        ['label' => 'Roles y permisos', 'route' => 'roles.index', 'icon' => 'shield-check'],
        ['label' => 'Bitácora', 'route' => 'bitacora.index', 'icon' => 'document-text'],
    ],
];
@endphp

{{-- Se incluye en el menú lateral (escritorio) y en el menú desplegable (móvil) --}}
<nav class="menu" aria-label="{{ $menuLabel ?? 'Menú principal' }}">
    @foreach ($menu as $grupo => $items)
        <div class="menu__group">
            <p class="menu__heading">{{ $grupo }}</p>
            <ul class="menu__list">
                @foreach ($items as $item)
                    <li>
                        <x-admin.sidebar-link :route="$item['route']" :icon="$item['icon']">
                            {{ $item['label'] }}
                        </x-admin.sidebar-link>
                    </li>
                @endforeach
            </ul>
        </div>
    @endforeach
</nav>
