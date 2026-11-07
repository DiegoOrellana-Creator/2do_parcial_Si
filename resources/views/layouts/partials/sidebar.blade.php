@php
use App\Models\User;

// Navegación según el perfil del usuario autenticado (ver User::perfil()).
// «route» es el nombre de una ruta registrada en routes/web.php; las funciones todavía sin
// implementar llevan «route» => null y se muestran desactivadas («Pronto»), nunca como enlaces
// a páginas inexistentes. «can» es el permiso que exige la ruta: sin él, el elemento aparece
// desactivado («Sin acceso»). Ocultar o mostrar un elemento no sustituye la autorización:
// las rutas se protegen en el servidor.
$perfil = Auth::user()->perfil();

$menus = [
    // Personal de la clínica (administración y recepción): menú de gestión.
    User::PERFIL_PERSONAL => [
        'Principal' => [
            ['label' => 'Dashboard', 'route' => 'dashboard', 'icon' => 'home'],
        ],
        'Gestión clínica' => [
            ['label' => 'Pacientes', 'route' => 'pacientes.index', 'icon' => 'users', 'can' => 'pacientes.ver'],
            ['label' => 'Médicos', 'route' => 'medicos.index', 'icon' => 'user', 'can' => 'medicos.ver'],
            ['label' => 'Especialidades', 'route' => 'especialidades.index', 'icon' => 'tag', 'can' => 'especialidades.ver'],
            ['label' => 'Horarios de atención', 'route' => 'horarios.index', 'icon' => 'clock', 'can' => 'horarios.ver'],
        ],
        'Citas' => [
            ['label' => 'Disponibilidad de citas', 'route' => 'disponibilidad.index', 'icon' => 'magnifying-glass', 'can' => 'disponibilidad.ver'],
            ['label' => 'Gestión de citas', 'route' => 'citas.index', 'icon' => 'clipboard', 'can' => 'citas.ver'],
            ['label' => 'Agenda médica', 'route' => 'agenda.index', 'icon' => 'calendar', 'can' => 'agenda.ver'],
        ],
        'Análisis' => [
            ['label' => 'Reportes', 'route' => 'reportes.index', 'icon' => 'chart-bar', 'can' => 'reportes.ver'],
        ],
        'Administración' => [
            ['label' => 'Usuarios', 'route' => 'usuarios.index', 'icon' => 'user-circle', 'can' => 'usuarios.gestionar'],
            ['label' => 'Roles y permisos', 'route' => 'roles.index', 'icon' => 'shield-check', 'can' => 'roles.gestionar'],
            ['label' => 'Bitácora', 'route' => 'bitacora.index', 'icon' => 'document-text', 'can' => 'bitacora.ver'],
        ],
        'Mi cuenta' => [
            ['label' => 'Mi perfil', 'route' => 'profile.edit', 'icon' => 'user-circle'],
        ],
    ],

    // Recepción: solo lo que atiende a diario, sin opciones de administración.
    'recepcion' => [
        'Principal' => [
            ['label' => 'Panel principal', 'route' => 'dashboard', 'icon' => 'home'],
        ],
        'Atención' => [
            ['label' => 'Pacientes', 'route' => 'pacientes.index', 'icon' => 'users', 'can' => 'pacientes.ver'],
            ['label' => 'Citas médicas', 'route' => 'citas.index', 'icon' => 'clipboard', 'can' => 'citas.ver'],
            ['label' => 'Agenda', 'route' => 'agenda.index', 'icon' => 'calendar', 'can' => 'agenda.ver'],
            ['label' => 'Médicos', 'route' => 'medicos.index', 'icon' => 'user', 'can' => 'medicos.ver'],
            ['label' => 'Especialidades', 'route' => 'especialidades.index', 'icon' => 'tag', 'can' => 'especialidades.ver'],
        ],
        'Mi cuenta' => [
            ['label' => 'Mi perfil', 'route' => 'profile.edit', 'icon' => 'user-circle'],
        ],
    ],

    User::PERFIL_MEDICO => [
        'Mi consulta' => [
            ['label' => 'Inicio', 'route' => 'dashboard', 'icon' => 'home'],
            ['label' => 'Mi agenda', 'route' => 'agenda.index', 'icon' => 'calendar'],
            ['label' => 'Mis citas', 'route' => 'consultas.index', 'icon' => 'clipboard'],
        ],
        'Mi cuenta' => [
            ['label' => 'Mi perfil', 'route' => 'profile.edit', 'icon' => 'user-circle'],
        ],
    ],

    User::PERFIL_PACIENTE => [
        'Mis citas' => [
            ['label' => 'Inicio', 'route' => 'dashboard', 'icon' => 'home'],
            ['label' => 'Disponibilidad médica', 'route' => 'mis-citas.disponibilidad', 'icon' => 'magnifying-glass', 'active' => ['mis-citas.disponibilidad']],
            ['label' => 'Solicitar cita', 'route' => 'mis-citas.create', 'icon' => 'plus', 'active' => ['mis-citas.create']],
            ['label' => 'Mis citas', 'route' => 'mis-citas.index', 'icon' => 'clipboard', 'active' => ['mis-citas.index', 'mis-citas.show']],
        ],
        'Mi cuenta' => [
            ['label' => 'Mi perfil', 'route' => 'profile.edit', 'icon' => 'user-circle'],
        ],
    ],

    // Cuenta sin perfil asignado: solo lo propio.
    User::PERFIL_SIN_PERFIL => [
        'Principal' => [
            ['label' => 'Inicio', 'route' => 'dashboard', 'icon' => 'home'],
        ],
        'Mi cuenta' => [
            ['label' => 'Mi perfil', 'route' => 'profile.edit', 'icon' => 'user-circle'],
        ],
    ],
];

$menu = $perfil === User::PERFIL_PERSONAL && Auth::user()->esRecepcionista()
    ? $menus['recepcion']
    : ($menus[$perfil] ?? $menus[User::PERFIL_SIN_PERFIL]);
$conCierreDeSesion = true;
@endphp

{{-- Se incluye en el menú lateral (escritorio) y en el menú desplegable (móvil) --}}
<nav class="menu" aria-label="{{ $menuLabel ?? 'Menú principal' }}" data-perfil="{{ $perfil }}">
    @foreach ($menu as $grupo => $items)
        <div class="menu__group">
            <p class="menu__heading">{{ $grupo }}</p>
            <ul class="menu__list">
                @foreach ($items as $item)
                    <li>
                        <x-admin.sidebar-link :route="$item['route']" :icon="$item['icon']" :can="$item['can'] ?? null" :active-on="$item['active'] ?? null">
                            {{ $item['label'] }}
                        </x-admin.sidebar-link>
                    </li>
                @endforeach

                @if ($conCierreDeSesion && $loop->last)
                    <li>
                        <!-- Cierre de sesión: formulario POST normal -->
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="nav-link nav-link--button">
                                <x-admin.icon name="logout" class="nav-link__icon" />
                                <span class="nav-link__label">Cerrar sesión</span>
                            </button>
                        </form>
                    </li>
                @endif
            </ul>
        </div>
    @endforeach
</nav>
