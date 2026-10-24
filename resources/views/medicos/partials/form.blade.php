@php
$usuario = $medico->usuario;
$editando = $medico->exists;
$seleccionadas = collect(old('especialidades', $editando ? $medico->especialidades->pluck('id_especialidad')->all() : []))
    ->map(fn ($id) => (int) $id)->all();
@endphp

@if ($errors->any())
    <div class="notice notice--error" role="alert">
        No se pudo guardar: revisa {{ $errors->count() === 1 ? 'el campo marcado' : 'los ' . $errors->count() . ' campos marcados' }}.
    </div>
@endif

<p class="form-legend-note"><span class="required-mark" aria-hidden="true">*</span> Campo obligatorio</p>

<!-- Datos personales -->
<fieldset class="form-section">
    <legend class="form-section__title">Datos personales</legend>

    <div class="form-grid">
        <div>
            <x-input-label for="nombre">Nombre <span class="required-mark" aria-hidden="true">*</span></x-input-label>
            <x-text-input id="nombre" name="nombre" type="text" maxlength="100" required autocomplete="given-name"
                          :value="old('nombre', $usuario?->nombre)" :aria-invalid="$errors->has('nombre') ? 'true' : null" />
            <x-input-error :messages="$errors->get('nombre')" />
        </div>

        <div>
            <x-input-label for="apellido">Apellido <span class="required-mark" aria-hidden="true">*</span></x-input-label>
            <x-text-input id="apellido" name="apellido" type="text" maxlength="100" required autocomplete="family-name"
                          :value="old('apellido', $usuario?->apellido)" :aria-invalid="$errors->has('apellido') ? 'true' : null" />
            <x-input-error :messages="$errors->get('apellido')" />
        </div>

        <div>
            <x-input-label for="ci" value="Carnet de identidad (CI)" />
            <x-text-input id="ci" name="ci" type="text" maxlength="20"
                          :value="old('ci', $usuario?->ci)" :aria-invalid="$errors->has('ci') ? 'true' : null" />
            <x-input-error :messages="$errors->get('ci')" />
        </div>

        <div>
            <x-input-label for="fecha_nacimiento" value="Fecha de nacimiento" />
            <x-text-input id="fecha_nacimiento" name="fecha_nacimiento" type="date" :max="now()->subDay()->toDateString()" autocomplete="bday"
                          :value="old('fecha_nacimiento', $usuario?->fecha_nacimiento ? \Illuminate\Support\Carbon::parse($usuario->fecha_nacimiento)->toDateString() : null)"
                          :aria-invalid="$errors->has('fecha_nacimiento') ? 'true' : null" />
            <x-input-error :messages="$errors->get('fecha_nacimiento')" />
        </div>

        <div>
            <x-input-label for="sexo" value="Sexo" />
            <select id="sexo" name="sexo" class="form-input" @error('sexo') aria-invalid="true" @enderror>
                <option value="">Sin especificar</option>
                @foreach (\App\Http\Requests\PacienteRequest::SEXOS as $sexo)
                    <option value="{{ $sexo }}" @selected(old('sexo', $usuario?->sexo) === $sexo)>{{ $sexo }}</option>
                @endforeach
            </select>
            <x-input-error :messages="$errors->get('sexo')" />
        </div>

        <div>
            <x-input-label for="telefono" value="Teléfono" />
            <x-text-input id="telefono" name="telefono" type="tel" maxlength="20" autocomplete="tel"
                          :value="old('telefono', $usuario?->telefono)" :aria-invalid="$errors->has('telefono') ? 'true' : null" />
            <x-input-error :messages="$errors->get('telefono')" />
        </div>

        <div class="form-grid__full">
            <x-input-label for="direccion" value="Dirección" />
            <textarea id="direccion" name="direccion" rows="2" maxlength="500" class="form-input" autocomplete="street-address"
                      @error('direccion') aria-invalid="true" @enderror>{{ old('direccion', $usuario?->direccion) }}</textarea>
            <x-input-error :messages="$errors->get('direccion')" />
        </div>
    </div>
</fieldset>

<!-- Datos profesionales -->
<fieldset class="form-section">
    <legend class="form-section__title">Datos profesionales</legend>

    <div class="form-grid">
        <div>
            <x-input-label for="matricula" value="Matrícula profesional" />
            <x-text-input id="matricula" name="matricula" type="text" maxlength="50" aria-describedby="matricula-ayuda"
                          :value="old('matricula', $medico->matricula)" :aria-invalid="$errors->has('matricula') ? 'true' : null" />
            <p id="matricula-ayuda" class="field-hint">Única. Letras, números y los signos . / -</p>
            <x-input-error :messages="$errors->get('matricula')" />
        </div>

        <div>
            <x-input-label for="codigo" value="Código interno" />
            <x-text-input id="codigo" name="codigo" type="text" maxlength="50" aria-describedby="codigo-ayuda"
                          :value="old('codigo', $medico->codigo)" :aria-invalid="$errors->has('codigo') ? 'true' : null" />
            <p id="codigo-ayuda" class="field-hint">Único. Letras, números, guiones y guiones bajos.</p>
            <x-input-error :messages="$errors->get('codigo')" />
        </div>
    </div>
</fieldset>

<!-- Especialidades -->
<fieldset class="form-section" @error('especialidades') aria-invalid="true" @enderror>
    <legend class="form-section__title">Especialidades que ejerce <span class="required-mark" aria-hidden="true">*</span></legend>

    @if ($especialidades->isEmpty())
        <p class="notice notice--info">No hay especialidades activas registradas. Regístralas primero en el módulo Especialidades.</p>
    @else
        <p class="field-hint">Selecciona al menos una. Solo se pueden añadir especialidades activas.</p>

        <div class="checkbox-grid">
            @foreach ($especialidades as $especialidad)
                @php($inactiva = $especialidad->estado !== \App\Models\Especialidad::ACTIVA)
                <label class="checkbox-option">
                    <input type="checkbox" name="especialidades[]" value="{{ $especialidad->id_especialidad }}"
                           @checked(in_array($especialidad->id_especialidad, $seleccionadas, true))>
                    <span>
                        {{ $especialidad->nombre }}
                        @if ($inactiva)
                            <span class="checkbox-option__note">(inactiva: solo puede conservarse)</span>
                        @endif
                    </span>
                </label>
            @endforeach
        </div>
    @endif

    <x-input-error :messages="$errors->get('especialidades')" />
    @foreach ($errors->get('especialidades.*') as $mensajes)
        <x-input-error :messages="$mensajes" />
    @endforeach
</fieldset>

<!-- Estado -->
<fieldset class="form-section">
    <legend class="form-section__title">Estado</legend>

    <div class="form-grid">
        <div>
            <x-input-label for="estado">Estado del médico <span class="required-mark" aria-hidden="true">*</span></x-input-label>
            <select id="estado" name="estado" required class="form-input" aria-describedby="estado-ayuda" @error('estado') aria-invalid="true" @enderror>
                @foreach (\App\Models\User::ESTADOS as $valor)
                    <option value="{{ $valor }}" @selected(old('estado', $usuario?->estado ?? \App\Models\User::ESTADO_ACTIVO) === $valor)>{{ $valor }}</option>
                @endforeach
            </select>
            <x-input-error :messages="$errors->get('estado')" />
        </div>
    </div>

    <p id="estado-ayuda" class="field-hint">
        Es el estado de la cuenta del médico: un médico inactivo no puede iniciar sesión ni debe recibir nuevas citas.
    </p>
</fieldset>

<!-- Cuenta -->
<fieldset class="form-section">
    <legend class="form-section__title">Cuenta de acceso</legend>

    <div class="form-grid">
        <div>
            <x-input-label for="email">Correo electrónico <span class="required-mark" aria-hidden="true">*</span></x-input-label>
            <x-text-input id="email" name="email" type="email" maxlength="100" required autocomplete="email"
                          :value="old('email', $usuario?->email)" :aria-invalid="$errors->has('email') ? 'true' : null" />
            <x-input-error :messages="$errors->get('email')" />
        </div>

        <div>
            <x-input-label for="username">Nombre de usuario <span class="required-mark" aria-hidden="true">*</span></x-input-label>
            <x-text-input id="username" name="username" type="text" minlength="3" maxlength="50" required autocomplete="off"
                          :value="old('username', $usuario?->username)" :aria-invalid="$errors->has('username') ? 'true' : null" />
            <x-input-error :messages="$errors->get('username')" />
        </div>

        @unless ($editando)
            <div>
                <x-input-label for="password">Contraseña <span class="required-mark" aria-hidden="true">*</span></x-input-label>
                <x-text-input id="password" name="password" type="password" minlength="8" maxlength="255" required autocomplete="new-password"
                              aria-describedby="password-ayuda" :aria-invalid="$errors->has('password') ? 'true' : null" />
                <p id="password-ayuda" class="field-hint">Mínimo 8 caracteres. El médico iniciará sesión con su correo electrónico y esta contraseña.</p>
                <x-input-error :messages="$errors->get('password')" />
            </div>

            <div>
                <x-input-label for="password_confirmation">Confirmar contraseña <span class="required-mark" aria-hidden="true">*</span></x-input-label>
                <x-text-input id="password_confirmation" name="password_confirmation" type="password" minlength="8" maxlength="255" required autocomplete="new-password" />
            </div>
        @endunless
    </div>
</fieldset>
