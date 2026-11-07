<x-guest-layout>
    <h1 class="auth-title">{{ $titulo }}</h1>

    <div class="notice {{ $exito ? 'notice--success' : 'notice--info' }}" role="status">{{ $mensaje }}</div>
</x-guest-layout>
