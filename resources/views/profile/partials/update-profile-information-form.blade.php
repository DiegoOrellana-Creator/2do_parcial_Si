@php($soloLectura = ! $user->puedeEditarSusDatos())

<section>
    <header>
        <h2 class="section-title">
            Datos de mi cuenta
        </h2>

        <p class="section-text">
            @if ($soloLectura)
                @if ($user->perfil() === \App\Models\User::PERFIL_PACIENTE)
                    Si necesitas cambiar alguno de estos datos, solicítalo en la recepción de la clínica.
                @else
                    Si necesitas cambiar alguno de estos datos, solicítalo al administrador.
                @endif
            @else
                Actualiza tu nombre y tu correo electrónico. Con ese correo inicias sesión.
            @endif
        </p>
    </header>

    @if ($soloLectura)
        <dl class="detail-list">
            <div><dt>Nombre</dt><dd>{{ $user->nombre }}</dd></div>
            <div><dt>Apellido</dt><dd>{{ $user->apellido }}</dd></div>
            <div><dt>Nombre de usuario</dt><dd>{{ $user->username }}</dd></div>
            <div><dt>Correo electrónico</dt><dd>{{ $user->email }}</dd></div>
        </dl>
    @else
        <form id="send-verification" method="post" action="{{ route('verification.send') }}">
            @csrf
        </form>

        <form method="post" action="{{ route('profile.update') }}" class="form-stack">
            @csrf
            @method('patch')

            <div>
                <x-input-label for="nombre" value="Nombre" />
                <x-text-input id="nombre" name="nombre" type="text" maxlength="100" :value="old('nombre', $user->nombre)" required autofocus autocomplete="given-name" />
                <x-input-error :messages="$errors->get('nombre')" />
            </div>

            <div>
                <x-input-label for="apellido" value="Apellido" />
                <x-text-input id="apellido" name="apellido" type="text" maxlength="100" :value="old('apellido', $user->apellido)" required autocomplete="family-name" />
                <x-input-error :messages="$errors->get('apellido')" />
            </div>

            <div>
                <x-input-label for="username" value="Nombre de usuario" />
                <x-text-input id="username" type="text" :value="$user->username" disabled aria-describedby="username-ayuda" />
                <p id="username-ayuda" class="form-hint">No se puede cambiar desde aquí.</p>
            </div>

            <div>
                <x-input-label for="email" value="Correo electrónico" />
                <x-text-input id="email" name="email" type="email" maxlength="100" :value="old('email', $user->email)" required autocomplete="username" />
                <x-input-error :messages="$errors->get('email')" />

                @if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $user->hasVerifiedEmail())
                    <div>
                        <p class="form-hint">
                            {{ __('Your email address is unverified.') }}

                            <button form="send-verification" class="link-button link-muted">
                                {{ __('Click here to re-send the verification email.') }}
                            </button>
                        </p>

                        @if (session('status') === 'verification-link-sent')
                            <p class="alert alert--success">
                                {{ __('A new verification link has been sent to your email address.') }}
                            </p>
                        @endif
                    </div>
                @endif
            </div>

            <div class="form-actions">
                <x-primary-button>Guardar</x-primary-button>

                @if (session('status') === 'profile-updated')
                    <p class="flash-message">Datos guardados.</p>
                @endif
            </div>
        </form>
    @endif
</section>
