@props(['route', 'icon'])

@php
// Si la ruta aún no existe, el enlace se muestra desactivado en lugar de provocar un error.
$exists = Route::has($route);
$pattern = str_contains($route, '.') ? Str::beforeLast($route, '.') . '.*' : $route;
$active = $exists && request()->routeIs($route, $pattern);
@endphp

@if ($exists)
    <a href="{{ route($route) }}"
       @if ($active) aria-current="page" @endif
       @class(['nav-link', 'is-active' => $active])>
        <x-admin.icon :name="$icon" class="nav-link__icon" />
        <span class="nav-link__label">{{ $slot }}</span>
    </a>
@else
    <span aria-disabled="true" title="Disponible próximamente" class="nav-link is-disabled">
        <x-admin.icon :name="$icon" class="nav-link__icon" />
        <span class="nav-link__label">{{ $slot }}</span>
        <span class="soon-tag">Pronto</span>
    </span>
@endif
