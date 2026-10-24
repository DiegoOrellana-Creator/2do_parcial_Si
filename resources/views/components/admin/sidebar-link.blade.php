@props(['route' => null, 'icon', 'can' => null, 'activeOn' => null])

@php
// Tres estados posibles:
//  - Módulo sin implementar (sin ruta registrada): desactivado con «Pronto».
//  - Ruta existente pero el usuario no tiene el permiso: desactivado con «Sin acceso».
//    La ruta sigue protegida en el servidor; aquí solo se evita mostrar un enlace inútil.
//  - Ruta existente y permitida: enlace normal, marcado como activo en sus páginas.
$exists = $route !== null && Route::has($route);
$permitido = $can === null || Gate::allows($can);
$pattern = $route !== null && str_contains($route, '.') ? Str::beforeLast($route, '.') . '.*' : $route;
// «activeOn» fija las rutas en las que el enlace queda activo cuando varias opciones comparten prefijo.
$active = $exists && $permitido && request()->routeIs(...($activeOn ?? [$route, $pattern]));
@endphp

@if ($exists && $permitido)
    <a href="{{ route($route) }}"
       @if ($active) aria-current="page" @endif
       @class(['nav-link', 'is-active' => $active])>
        <x-admin.icon :name="$icon" class="nav-link__icon" />
        <span class="nav-link__label">{{ $slot }}</span>
    </a>
@else
    <span aria-disabled="true"
          title="{{ $exists ? 'No tienes permiso para acceder a este módulo' : 'Disponible próximamente' }}"
          class="nav-link is-disabled">
        <x-admin.icon :name="$icon" class="nav-link__icon" />
        <span class="nav-link__label">{{ $slot }}</span>
        <span class="soon-tag">{{ $exists ? 'Sin acceso' : 'Pronto' }}</span>
    </span>
@endif
