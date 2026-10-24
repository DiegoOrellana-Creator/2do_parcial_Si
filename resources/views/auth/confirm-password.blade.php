<x-guest-layout>
    <h2 class="auth-title auth-title--with-text">Confirmar contraseña</h2>

    <p class="auth-text">
        Esta es un área segura del sistema. Confirma tu contraseña antes de continuar.
    </p>

    <form method="POST" action="{{ route('password.confirm') }}">
        @csrf

        <!-- Password -->
        <div class="field field--last">
            <label for="password" class="field__label">Contraseña</label>
            <input id="password" type="password" name="password" required autocomplete="current-password" class="field__input" />
            <x-input-error :messages="$errors->get('password')" />
        </div>

        <button type="submit" class="btn-pill">
            Confirmar
        </button>
    </form>
</x-guest-layout>
