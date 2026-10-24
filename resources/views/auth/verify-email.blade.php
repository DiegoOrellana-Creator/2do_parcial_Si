<x-guest-layout>
    <h2 class="mb-4 text-center text-2xl font-semibold text-brand-950">Verifica tu correo</h2>

    <div class="mb-4 text-sm text-gray-600">
        Antes de continuar, verifica tu dirección de correo electrónico con el enlace que te enviamos. Si no recibiste el correo, podemos enviarte otro.
    </div>

    @if (session('status') == 'verification-link-sent')
        <div class="mb-4 font-medium text-sm text-green-600">
            Te enviamos un nuevo enlace de verificación al correo electrónico registrado.
        </div>
    @endif

    <div class="mt-4 flex items-center justify-between">
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf

            <div>
                <x-primary-button>
                    Reenviar correo
                </x-primary-button>
            </div>
        </form>

        <form method="POST" action="{{ route('logout') }}">
            @csrf

            <button type="submit" class="underline text-sm text-gray-600 hover:text-gray-900 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-brand-500">
                Cerrar sesión
            </button>
        </form>
    </div>
</x-guest-layout>
