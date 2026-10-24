<x-guest-layout>
    <h2 class="auth-title">Restablecer contraseña</h2>

    <form method="POST" action="{{ route('password.store') }}">
        @csrf

        <!-- Password Reset Token -->
        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <!-- Email Address -->
        <div class="field">
            <label for="email" class="field__label">Correo electrónico</label>
            <input id="email" type="email" name="email" value="{{ old('email', $request->email) }}" required autofocus autocomplete="username" class="field__input" />
            <x-input-error :messages="$errors->get('email')" />
        </div>

        <!-- Password -->
        <div class="field">
            <label for="password" class="field__label">Nueva contraseña</label>
            <input id="password" type="password" name="password" required autocomplete="new-password" class="field__input" />
            <x-input-error :messages="$errors->get('password')" />
        </div>

        <!-- Confirm Password -->
        <div class="field field--last">
            <label for="password_confirmation" class="field__label">Confirmar contraseña</label>
            <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password" class="field__input" />
            <x-input-error :messages="$errors->get('password_confirmation')" />
        </div>

        <button type="submit" class="btn-pill">
            Restablecer contraseña
        </button>
    </form>
</x-guest-layout>
