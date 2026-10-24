<x-guest-layout>
    <h2 class="mb-10 text-center text-3xl font-semibold text-brand-950">Restablecer contraseña</h2>

    <form method="POST" action="{{ route('password.store') }}">
        @csrf

        <!-- Password Reset Token -->
        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <!-- Email Address -->
        <div class="mb-8">
            <label for="email" class="block text-sm font-medium text-gray-500">Correo electrónico</label>
            <input id="email" type="email" name="email" value="{{ old('email', $request->email) }}" required autofocus autocomplete="username"
                   class="mt-2 block w-full border-0 border-b border-gray-300 bg-transparent px-0 py-1 text-gray-900 focus:border-brand-950 focus:ring-0 outline-none" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <!-- Password -->
        <div class="mb-8">
            <label for="password" class="block text-sm font-medium text-gray-500">Nueva contraseña</label>
            <input id="password" type="password" name="password" required autocomplete="new-password"
                   class="mt-2 block w-full border-0 border-b border-gray-300 bg-transparent px-0 py-1 text-gray-900 focus:border-brand-950 focus:ring-0 outline-none" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <!-- Confirm Password -->
        <div class="mb-10">
            <label for="password_confirmation" class="block text-sm font-medium text-gray-500">Confirmar contraseña</label>
            <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password"
                   class="mt-2 block w-full border-0 border-b border-gray-300 bg-transparent px-0 py-1 text-gray-900 focus:border-brand-950 focus:ring-0 outline-none" />
            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
        </div>

        <div>
            <button type="submit" class="w-full rounded-full bg-brand-950 py-3.5 text-center text-lg font-medium text-white transition hover:bg-brand-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2">
                Restablecer contraseña
            </button>
        </div>
    </form>
</x-guest-layout>
