<x-guest-layout>
    <h2 class="auth-title auth-title--with-text">Recuperar contraseña</h2>

    <p class="auth-text">
        ¿Olvidaste tu contraseña? No te preocupes. Ingresa el correo electrónico asociado a tu cuenta en la Clínica Santa Cruz y te enviaremos un enlace para crear una nueva.
    </p>

    <!-- Session Status -->
    <x-auth-session-status :status="session('status')" />

    <form method="POST" action="{{ route('password.email') }}">
        @csrf

        <!-- Email Address -->
        <div class="field field--last">
            <label for="email" class="field__label">Correo electrónico</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username" class="field__input" />
            <x-input-error :messages="$errors->get('email')" />
        </div>

        <button type="submit" class="btn-pill">
            Enviar enlace
        </button>

        <div class="auth-footer">
            <a href="{{ route('login') }}" class="link">
                Volver al inicio de sesión
            </a>
        </div>
    </form>
</x-guest-layout>
