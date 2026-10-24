<x-guest-layout>
    <h2 class="mb-4 text-center text-3xl font-semibold text-brand-950">Recuperar contraseña</h2>

    <p class="mb-8 text-center text-sm text-gray-500">
        ¿Olvidaste tu contraseña? No te preocupes. Ingresa el correo electrónico asociado a tu cuenta en la Clínica Santa Cruz y te enviaremos un enlace para crear una nueva.
    </p>

    <!-- Session Status -->
    <x-auth-session-status class="mb-4" :status="session('status')" />

    <form method="POST" action="{{ route('password.email') }}">
        @csrf

        <!-- Email Address -->
        <div class="mb-10">
            <label for="email" class="block text-sm font-medium text-gray-500">Correo electrónico</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                   class="mt-2 block w-full border-0 border-b border-gray-300 bg-transparent px-0 py-1 text-gray-900 focus:border-brand-950 focus:ring-0 outline-none" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <div>
            <button type="submit" class="w-full rounded-full bg-brand-950 py-3.5 text-center text-lg font-medium text-white transition hover:bg-brand-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2">
                Enviar enlace
            </button>
        </div>

        <div class="mt-6 text-center">
            <a href="{{ route('login') }}" class="text-sm font-semibold text-brand-950 hover:underline">
                Volver al inicio de sesión
            </a>
        </div>
    </form>
</x-guest-layout>
