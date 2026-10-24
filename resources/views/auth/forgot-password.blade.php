<x-guest-layout>
    <h2 class="mb-4 text-center text-2xl font-semibold text-brand-950">Recuperar contraseña</h2>

    <div class="mb-4 text-sm text-gray-600">
        ¿Olvidaste tu contraseña? No te preocupes. Ingresa el correo electrónico asociado a tu cuenta en la Clínica Santa Cruz y te enviaremos un enlace para crear una nueva.
    </div>

    <!-- Session Status -->
    <x-auth-session-status class="mb-4" :status="session('status')" />

    <form method="POST" action="{{ route('password.email') }}">
        @csrf

        <!-- Email Address -->
        <div>
            <x-input-label for="email" value="Correo electrónico" />
            <x-text-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email')" required autofocus />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <div class="flex items-center justify-between mt-4">
            <a href="{{ route('login') }}" class="text-sm font-medium text-brand-950 hover:underline">
                Volver al inicio de sesión
            </a>

            <x-primary-button>
                Enviar enlace
            </x-primary-button>
        </div>
    </form>
</x-guest-layout>
