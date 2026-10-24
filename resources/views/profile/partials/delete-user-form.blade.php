<section>
    <header>
        <h2 class="section-title">
            {{ __('Delete Account') }}
        </h2>

        <p class="section-text">
            {{ __('Once your account is deleted, all of its resources and data will be permanently deleted. Before deleting your account, please download any data or information that you wish to retain.') }}
        </p>
    </header>

    {{--
        Confirmación sin JavaScript: el botón abre la ventana (<details>), pero la
        cuenta solo se elimina al escribir la contraseña y pulsar el botón del formulario.
    --}}
    <x-modal name="confirm-user-deletion" :show="$errors->userDeletion->isNotEmpty()" trigger-class="btn btn--danger" class="form-stack">
        <x-slot name="trigger">{{ __('Delete Account') }}</x-slot>

        <form method="post" action="{{ route('profile.destroy') }}" class="modal__body">
            @csrf
            @method('delete')

            <h2 class="section-title">
                {{ __('Are you sure you want to delete your account?') }}
            </h2>

            <p class="section-text">
                {{ __('Once your account is deleted, all of its resources and data will be permanently deleted. Please enter your password to confirm you would like to permanently delete your account.') }}
            </p>

            <div class="modal__field">
                <x-input-label for="password" value="{{ __('Password') }}" class="sr-only" />

                <x-text-input
                    id="password"
                    name="password"
                    type="password"
                    class="form-input--narrow"
                    placeholder="{{ __('Password') }}"
                />

                <x-input-error :messages="$errors->userDeletion->get('password')" />
            </div>

            <div class="modal__actions">
                {{-- Cerrar sin JavaScript: recarga el perfil con la ventana cerrada --}}
                <a href="{{ route('profile.edit') }}" class="btn btn--secondary">
                    {{ __('Cancel') }}
                </a>

                <x-danger-button>
                    {{ __('Delete Account') }}
                </x-danger-button>
            </div>
        </form>
    </x-modal>
</section>
