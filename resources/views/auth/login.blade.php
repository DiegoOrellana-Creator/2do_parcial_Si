<x-guest-layout>
    <x-auth-session-status class="mb-4" :status="session('status')" />

    <form method="POST" action="{{ route('login') }}">
        @csrf

        <h2 class="mb-10 text-center text-3xl font-semibold text-brand-950">Iniciar sesión</h2>

        <!-- Email Address -->
        <div class="mb-8">
            <label for="email" class="block text-sm font-medium text-gray-500">Correo electrónico</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                   class="mt-2 block w-full border-0 border-b border-gray-300 bg-transparent px-0 py-1 text-gray-900 focus:border-brand-950 focus:ring-0 outline-none" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <!-- Password -->
        <div class="mb-10">
            <div class="flex items-end justify-between">
                <label for="password" class="block text-sm font-medium text-gray-500">Contraseña</label>
                @if (Route::has('password.request'))
                    <a href="{{ route('password.request') }}" class="text-xs font-semibold text-brand-950 hover:underline">
                        ¿Olvidaste tu contraseña?
                    </a>
                @endif
            </div>
            <input id="password" type="password" name="password" required autocomplete="current-password"
                   class="mt-2 block w-full border-0 border-b border-gray-300 bg-transparent px-0 py-1 text-gray-900 focus:border-brand-950 focus:ring-0 outline-none" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <!-- Botón Login -->
        <div>
            <button type="submit" class="w-full rounded-full bg-brand-950 py-3.5 text-center text-lg font-medium text-white transition hover:bg-brand-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2">
                Ingresar
            </button>
        </div>
    </form>
</x-guest-layout>
