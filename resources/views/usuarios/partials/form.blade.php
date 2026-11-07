@if ($errors->any())
    <div class="notice notice--error" role="alert">
        No se pudo guardar: revisa {{ $errors->count() === 1 ? 'el campo marcado' : 'los ' . $errors->count() . ' campos marcados' }}.
    </div>
@endif

<p class="form-legend-note"><span class="required-mark" aria-hidden="true">*</span> Campo obligatorio</p>

<fieldset class="form-section">
    <legend class="form-section__title">Datos personales</legend>

    <div class="form-grid">
        <div>
            <x-input-label for="nombre">Nombre <span class="required-mark" aria-hidden="true">*</span></x-input-label>
            <x-text-input id="nombre" name="nombre" type="text" maxlength="100" required :value="old('nombre', $usuario->nombre)" :aria-invalid="$errors->has('nombre') ? 'true' : null" />
            <x-input-error :messages="$errors->get('nombre')" />
        </div>

        <div>
            <x-input-label for="apellido">Apellido <span class="required-mark" aria-hidden="true">*</span></x-input-label>
            <x-text-input id="apellido" name="apellido" type="text" maxlength="100" required :value="old('apellido', $usuario->apellido)" :aria-invalid="$errors->has('apellido') ? 'true' : null" />
            <x-input-error :messages="$errors->get('apellido')" />
        </div>

        <div>
            <x-input-label for="ci" value="Carnet de identidad (CI)" />
            <x-text-input id="ci" name="ci" type="text" maxlength="20" :value="old('ci', $usuario->ci)" :aria-invalid="$errors->has('ci') ? 'true' : null" />
            <x-input-error :messages="$errors->get('ci')" />
        </div>

        <div>
            <x-input-label for="telefono" value="Teléfono" />
            <x-text-input id="telefono" name="telefono" type="tel" maxlength="20" :value="old('telefono', $usuario->telefono)" :aria-invalid="$errors->has('telefono') ? 'true' : null" />
            <x-input-error :messages="$errors->get('telefono')" />
        </div>

        <div class="form-grid__full">
            <x-input-label for="direccion" value="Dirección" />
            <textarea id="direccion" name="direccion" rows="2" maxlength="500" class="form-input" @error('direccion') aria-invalid="true" @enderror>{{ old('direccion', $usuario->direccion) }}</textarea>
            <x-input-error :messages="$errors->get('direccion')" />
        </div>
    </div>
</fieldset>

<fieldset class="form-section">
    <legend class="form-section__title">Cuenta de acceso</legend>

    <div class="form-grid">
        @unless ($usuario->exists)
            <div>
                <x-input-label for="tipo">Tipo de cuenta <span class="required-mark" aria-hidden="true">*</span></x-input-label>
                <select id="tipo" name="tipo" required class="form-input" @error('tipo') aria-invalid="true" @enderror>
                    @foreach ($tipos as $tipo)
                        <option value="{{ $tipo }}" @selected(old('tipo', 'Recepcionista') === $tipo)>{{ $tipo }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('tipo')" />
            </div>
        @endunless

        <div>
            <x-input-label for="email">Correo electrónico <span class="required-mark" aria-hidden="true">*</span></x-input-label>
            <x-text-input id="email" name="email" type="email" maxlength="100" required autocomplete="off" aria-describedby="email-ayuda"
                          :value="old('email', $usuario->email)" :aria-invalid="$errors->has('email') ? 'true' : null" />
            <p id="email-ayuda" class="field-hint">Con este correo se inicia sesión.</p>
            <x-input-error :messages="$errors->get('email')" />
        </div>

        @if ($usuario->exists)
            <div>
                <x-input-label value="Nombre de usuario" />
                <p class="form-static">{{ $usuario->username }}</p>
            </div>

            @if ($puedeCambiarEstado)
                <div>
                    <x-input-label for="estado" value="Estado de la cuenta" />
                    <select id="estado" name="estado" class="form-input" aria-describedby="estado-ayuda" @error('estado') aria-invalid="true" @enderror>
                        @foreach (\App\Models\User::ESTADOS as $valor)
                            <option value="{{ $valor }}" @selected(old('estado', $usuario->estado) === $valor)>{{ $valor }}</option>
                        @endforeach
                    </select>
                    <p id="estado-ayuda" class="field-hint">Una cuenta inactiva no puede iniciar sesión y conserva todos sus datos.</p>
                    <x-input-error :messages="$errors->get('estado')" />
                </div>
            @endif
        @else
            <div>
                <x-input-label for="username">Nombre de usuario <span class="required-mark" aria-hidden="true">*</span></x-input-label>
                <x-text-input id="username" name="username" type="text" minlength="3" maxlength="50" required autocomplete="off"
                              :value="old('username')" :aria-invalid="$errors->has('username') ? 'true' : null" />
                <x-input-error :messages="$errors->get('username')" />
            </div>

            <div>
                <x-input-label for="password">Contraseña <span class="required-mark" aria-hidden="true">*</span></x-input-label>
                <x-text-input id="password" name="password" type="password" minlength="8" maxlength="255" required autocomplete="new-password"
                              :aria-invalid="$errors->has('password') ? 'true' : null" />
                <x-input-error :messages="$errors->get('password')" />
            </div>

            <div>
                <x-input-label for="password_confirmation">Confirmar contraseña <span class="required-mark" aria-hidden="true">*</span></x-input-label>
                <x-text-input id="password_confirmation" name="password_confirmation" type="password" minlength="8" maxlength="255" required autocomplete="new-password" />
            </div>
        @endif
    </div>
</fieldset>

@if ($usuario->exists && $rolesAsignables->isNotEmpty())
    <fieldset class="form-section">
        <legend class="form-section__title">Roles personalizados</legend>
        <input type="hidden" name="roles_enviados" value="1">
        <p class="field-hint">Suman los permisos de cada rol. Los roles del sistema no se cambian desde aquí.</p>

        <div class="checkbox-grid">
            @foreach ($rolesAsignables as $rol)
                <label class="checkbox-option">
                    <input type="checkbox" name="roles[]" value="{{ $rol->id_rol }}" @checked(in_array((int) $rol->id_rol, array_map('intval', old('roles', $rolesActivos)), true))>
                    <span>{{ $rol->nombre }}</span>
                </label>
            @endforeach
        </div>
        @foreach ($errors->get('roles.*') as $mensajes)
            <x-input-error :messages="$mensajes" />
        @endforeach
    </fieldset>
@endif
