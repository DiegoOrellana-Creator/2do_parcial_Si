<section>
    <header>
        <h2 class="section-title">
            {{ __('Delete Account') }}
        </h2>

        <p class="section-text">
            Solo puedes eliminar una cuenta que no tenga vínculos con la clínica. La cuenta administrativa principal no se puede
            eliminar. Si tienes una ficha de paciente, citas como médico, un rol del personal o acciones registradas en la auditoría,
            solicita la baja al personal de la clínica.
        </p>
    </header>

    <x-input-error :messages="$errors->userDeletion->get('cuenta')" class="form-errors notice notice--error" role="alert" />

    {{--
        Confirmación sin JavaScript: el botón abre la ventana (<details>), pero la
        cuenta solo se elimina al escribir la contraseña y pulsar el botón del formulario.
    --}}
    <x-modal name="confirm-user-deletion" :show="$errors->userDeletion->has('password')" trigger-class="btn btn--danger" class="form-stack">
        <x-slot name="trigger">{{ __('Delete Account') }}</x-slot>

        <form method="post" action="{{ route('profile.destroy') }}" class="modal__body">
            @csrf
            @method('delete')

            <h2 class="section-title">
                {{ __('Are you sure you want to delete your account?') }}
            </h2>

            <p class="section-text">
                Se eliminarán tu cuenta de acceso, tus datos personales, tus notificaciones y tus preferencias.
                Si la cuenta tiene vínculos con la clínica, no se borrará nada y verás el motivo.
                Escribe tu contraseña para confirmar. Esta acción no se puede deshacer.
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
