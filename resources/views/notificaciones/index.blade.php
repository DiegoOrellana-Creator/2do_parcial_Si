<x-app-layout>
    <x-slot name="header">
        <div class="page-heading">
            <div>
                <h1 class="page-title">Notificaciones</h1>
                <p class="page-subtitle">
                    {{ $sinLeer === 0 ? 'No tienes notificaciones sin leer.' : ($sinLeer === 1 ? 'Tienes 1 notificación sin leer.' : "Tienes {$sinLeer} notificaciones sin leer.") }}
                </p>
            </div>

            @if ($sinLeer > 0)
                <form method="POST" action="{{ route('notificaciones.leidas') }}">
                    @csrf
                    @method('PATCH')
                    <button type="submit" class="btn btn--secondary">Marcar todas como leídas</button>
                </form>
            @endif
        </div>
    </x-slot>

    <div class="page">
        <x-admin.flash />

        <x-admin.card :padding="false">
            @forelse ($notificaciones as $notificacion)
                <div @class(['contact-item', 'notificacion', 'notificacion--nueva' => ! $notificacion->leido])>
                    <p class="item-title">
                        @unless ($notificacion->leido)
                            <span class="notificacion__punto" aria-hidden="true"></span><span class="sr-only">Sin leer: </span>
                        @endunless
                        {{ $notificacion->tipo }}
                    </p>
                    <p class="section-text">{{ $notificacion->mensaje }}</p>
                    <p class="item-meta">{{ $notificacion->fecha_envio?->format('d/m/Y H:i') }}</p>
                </div>
            @empty
                <div class="empty-state">
                    <p class="empty-state__title">Todavía no tienes notificaciones.</p>
                    <p class="empty-state__text">Aquí verás los avisos sobre tus citas, pagos y tu cuenta.</p>
                </div>
            @endforelse

            @if ($notificaciones->hasPages())
                <div class="list-footer">
                    <p class="list-footer__summary">Mostrando {{ $notificaciones->firstItem() }}–{{ $notificaciones->lastItem() }} de {{ $notificaciones->total() }}</p>
                    {{ $notificaciones->links('pagination.clinica') }}
                </div>
            @endif
        </x-admin.card>
    </div>
</x-app-layout>
