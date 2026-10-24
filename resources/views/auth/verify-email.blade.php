<x-guest-layout>
    <h2 class="auth-title auth-title--with-text">Verifica tu correo</h2>

    <p class="auth-text">
        Antes de continuar, verifica tu dirección de correo electrónico con el enlace que te enviamos. Si no recibiste el correo, podemos enviarte otro.
    </p>

    @if (session('status') == 'verification-link-sent')
        <div class="alert alert--success alert--center">
            Te enviamos un nuevo enlace de verificación al correo electrónico registrado.
        </div>
    @endif

    <form method="POST" action="{{ route('verification.send') }}">
        @csrf

        <button type="submit" class="btn-pill">
            Reenviar correo
        </button>
    </form>

    <form method="POST" action="{{ route('logout') }}" class="auth-footer">
        @csrf

        <button type="submit" class="link link-button">
            Cerrar sesión
        </button>
    </form>
</x-guest-layout>
