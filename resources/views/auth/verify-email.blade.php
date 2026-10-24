<x-guest-layout>
    <h2 class="mb-4 text-center text-3xl font-semibold text-brand-950">Verifica tu correo</h2>

    <p class="mb-8 text-center text-sm text-gray-500">
        Antes de continuar, verifica tu dirección de correo electrónico con el enlace que te enviamos. Si no recibiste el correo, podemos enviarte otro.
    </p>

    @if (session('status') == 'verification-link-sent')
        <div class="mb-6 text-center font-medium text-sm text-green-600">
            Te enviamos un nuevo enlace de verificación al correo electrónico registrado.
        </div>
    @endif

    <form method="POST" action="{{ route('verification.send') }}">
        @csrf

        <button type="submit" class="w-full rounded-full bg-brand-950 py-3.5 text-center text-lg font-medium text-white transition hover:bg-brand-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2">
            Reenviar correo
        </button>
    </form>

    <form method="POST" action="{{ route('logout') }}" class="mt-6 text-center">
        @csrf

        <button type="submit" class="text-sm font-semibold text-brand-950 hover:underline rounded-md focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-brand-500">
            Cerrar sesión
        </button>
    </form>
</x-guest-layout>
