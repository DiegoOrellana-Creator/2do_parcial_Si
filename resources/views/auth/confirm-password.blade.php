<x-guest-layout>
    <h2 class="mb-4 text-center text-3xl font-semibold text-brand-950">Confirmar contraseña</h2>

    <p class="mb-8 text-center text-sm text-gray-500">
        Esta es un área segura del sistema. Confirma tu contraseña antes de continuar.
    </p>

    <form method="POST" action="{{ route('password.confirm') }}">
        @csrf

        <!-- Password -->
        <div class="mb-10">
            <label for="password" class="block text-sm font-medium text-gray-500">Contraseña</label>
            <input id="password" type="password" name="password" required autocomplete="current-password"
                   class="mt-2 block w-full border-0 border-b border-gray-300 bg-transparent px-0 py-1 text-gray-900 focus:border-brand-950 focus:ring-0 outline-none" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <div>
            <button type="submit" class="w-full rounded-full bg-brand-950 py-3.5 text-center text-lg font-medium text-white transition hover:bg-brand-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2">
                Confirmar
            </button>
        </div>
    </form>
</x-guest-layout>
