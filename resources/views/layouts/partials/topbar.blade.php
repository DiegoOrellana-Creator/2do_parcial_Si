@php
$user = Auth::user();
$iniciales = Str::upper(Str::substr($user->nombre ?? '', 0, 1) . Str::substr($user->apellido ?? '', 0, 1));
@endphp

<div class="topbar">
    <!-- Menú móvil: <details> nativo, sin JavaScript -->
    <details class="mobile-menu">
        <summary class="icon-button mobile-menu__toggle">
            <x-admin.icon name="bars-3" class="icon--lg icon-open" />
            <x-admin.icon name="x-mark" class="icon--lg icon-close" />
            <span class="sr-only">Menú principal</span>
        </summary>

        <div class="mobile-menu__panel">
            @include('layouts.partials.sidebar', ['menuLabel' => 'Menú principal (móvil)'])
        </div>
    </details>

    <a href="{{ route('dashboard') }}" class="topbar__logo">
        <x-application-logo />
    </a>

    <!-- Buscador (solo personal de la clínica): visual hasta que exista la búsqueda -->
    @if ($user->perfil() === \App\Models\User::PERFIL_PERSONAL)
        <div class="topbar__search">
            <label for="busqueda-global" class="sr-only">Buscar</label>
            <x-admin.icon name="magnifying-glass" class="icon--sm" />
            <input id="busqueda-global" type="search" disabled
                   placeholder="Buscar pacientes, médicos o citas (próximamente)" />
        </div>
    @endif

    <div class="topbar__actions">
        <!-- Notificaciones: solo visual hasta que exista el módulo -->
        <button type="button" disabled title="Notificaciones (próximamente)" class="icon-button"
                aria-label="Notificaciones, disponible próximamente">
            <x-admin.icon name="bell" class="icon--lg" />
        </button>

        <div class="topbar__divider" aria-hidden="true"></div>

        <!-- Menú de usuario: <details> nativo, sin JavaScript -->
        <x-dropdown align="right">
            <x-slot name="trigger">
                <span class="user-chip__avatar" aria-hidden="true">{{ $iniciales }}</span>
                <span class="user-chip__text">
                    <span class="user-chip__name">{{ $user->nombre }} {{ $user->apellido }}</span>
                    <span class="user-chip__email">{{ $user->email }}</span>
                </span>
                <span class="sr-only">Menú de usuario</span>
                <x-admin.icon name="chevron-down" class="icon--sm user-chip__chevron" />
            </x-slot>

            <x-slot name="content">
                <div class="dropdown__header dropdown__header--mobile">
                    <p class="user-chip__name">{{ $user->nombre }} {{ $user->apellido }}</p>
                    <p class="user-chip__email">{{ $user->email }}</p>
                </div>

                <x-dropdown-link :href="route('profile.edit')">
                    Mi perfil
                </x-dropdown-link>

                <!-- Cierre de sesión: formulario POST normal -->
                <form method="POST" action="{{ route('logout') }}">
                    @csrf

                    <button type="submit" class="dropdown__link">
                        Cerrar sesión
                    </button>
                </form>
            </x-slot>
        </x-dropdown>
    </div>
</div>
