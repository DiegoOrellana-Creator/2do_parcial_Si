<x-guest-layout>
    <x-auth-session-status :status="session('status')" />

    <form method="POST" action="{{ route('login') }}">
        @csrf

        <h2 class="auth-title">Iniciar sesión</h2>

        <!-- Email Address -->
        <div class="field">
            <label for="email" class="field__label">Correo electrónico</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username" class="field__input" />
            <x-input-error :messages="$errors->get('email')" />
        </div>

        <!-- Password -->
        <div class="field field--last">
            <div class="field__header">
                <label for="password" class="field__label">Contraseña</label>
                @if (Route::has('password.request'))
                    <a href="{{ route('password.request') }}" class="link link--small">
                        ¿Olvidaste tu contraseña?
                    </a>
                @endif
            </div>
            <input id="password" type="password" name="password" required autocomplete="current-password" class="field__input" />
            <x-input-error :messages="$errors->get('password')" />
        </div>

        <!-- Botón Login -->
        <button type="submit" class="btn-pill">
            Ingresar
        </button>
    </form>
</x-guest-layout>
