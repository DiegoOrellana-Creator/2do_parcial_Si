@php
$usuario = $paciente->usuario;
$info = $paciente->informacionMedica;
$contacto = $paciente->contactosEmergencia->sortBy('id_contacto')->first();
$totalContactos = $paciente->contactosEmergencia->count();
$editando = $paciente->exists;
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
            <x-text-input id="fecha_nacimiento" name="fecha_nacimiento" type="date" :max="now()->toDateString()" autocomplete="bday"
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

<!-- Cuenta del paciente -->
<fieldset class="form-section">
    <legend class="form-section__title">Cuenta del paciente</legend>

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
                          aria-describedby="username-ayuda"
                          :value="old('username', $usuario?->username)" :aria-invalid="$errors->has('username') ? 'true' : null" />
            <p id="username-ayuda" class="field-hint">Letras, números, guiones y guiones bajos, sin espacios.</p>
            <x-input-error :messages="$errors->get('username')" />
        </div>
    </div>

    @unless ($editando)
        <p class="field-hint">
            Se asignará una contraseña aleatoria. El paciente podrá crear la suya con «¿Olvidaste tu contraseña?» en la pantalla de acceso.
        </p>
    @endunless
</fieldset>

<!-- Ficha clínica -->
<fieldset class="form-section">
    <legend class="form-section__title">Ficha clínica</legend>

    <div class="form-grid">
        <div>
            <x-input-label for="nro_ficha" value="Número de ficha" />
            <x-text-input id="nro_ficha" name="nro_ficha" type="text" maxlength="50"
                          :value="old('nro_ficha', $paciente->nro_ficha)" :aria-invalid="$errors->has('nro_ficha') ? 'true' : null" />
            <x-input-error :messages="$errors->get('nro_ficha')" />
        </div>

        <div>
            <x-input-label for="tipo_sangre" value="Tipo de sangre" />
            <select id="tipo_sangre" name="tipo_sangre" class="form-input" @error('tipo_sangre') aria-invalid="true" @enderror>
                <option value="">Sin especificar</option>
                @foreach (\App\Http\Requests\PacienteRequest::TIPOS_SANGRE as $tipo)
                    <option value="{{ $tipo }}" @selected(old('tipo_sangre', $info?->tipo_sangre) === $tipo)>{{ $tipo }}</option>
                @endforeach
            </select>
            <x-input-error :messages="$errors->get('tipo_sangre')" />
        </div>

        @foreach ([
            'alergias' => 'Alergias',
            'enfermedades_cronicas' => 'Enfermedades crónicas',
            'medicamentos' => 'Medicamentos actuales',
            'antecedentes' => 'Antecedentes',
        ] as $campo => $etiqueta)
            <div class="form-grid__full">
                <x-input-label :for="$campo" :value="$etiqueta" />
                <textarea id="{{ $campo }}" name="{{ $campo }}" rows="2" maxlength="2000" class="form-input"
                          @error($campo) aria-invalid="true" @enderror>{{ old($campo, $info?->{$campo}) }}</textarea>
                <x-input-error :messages="$errors->get($campo)" />
            </div>
        @endforeach
    </div>
</fieldset>

<!-- Contacto de emergencia -->
<fieldset class="form-section">
    <legend class="form-section__title">Contacto de emergencia</legend>

    @if ($totalContactos > 1)
        <p class="field-hint">Este paciente tiene {{ $totalContactos }} contactos registrados; aquí se edita el principal.</p>
    @endif

    <div class="form-grid">
        <div>
            <x-input-label for="contacto_nombre" value="Nombre completo" />
            <x-text-input id="contacto_nombre" name="contacto_nombre" type="text" maxlength="100"
                          :value="old('contacto_nombre', $contacto?->nombre)" :aria-invalid="$errors->has('contacto_nombre') ? 'true' : null" />
            <x-input-error :messages="$errors->get('contacto_nombre')" />
        </div>

        <div>
            <x-input-label for="contacto_parentesco" value="Parentesco" />
            <x-text-input id="contacto_parentesco" name="contacto_parentesco" type="text" maxlength="50"
                          :value="old('contacto_parentesco', $contacto?->parentesco)" :aria-invalid="$errors->has('contacto_parentesco') ? 'true' : null" />
            <x-input-error :messages="$errors->get('contacto_parentesco')" />
        </div>

        <div>
            <x-input-label for="contacto_telefono" value="Teléfono del contacto" />
            <x-text-input id="contacto_telefono" name="contacto_telefono" type="tel" maxlength="20"
                          :value="old('contacto_telefono', $contacto?->telefono)" :aria-invalid="$errors->has('contacto_telefono') ? 'true' : null" />
            <x-input-error :messages="$errors->get('contacto_telefono')" />
        </div>
    </div>

    @if ($editando && $contacto)
        <p class="field-hint">Si dejas vacíos los tres campos, se quitará este contacto de emergencia.</p>
    @endif
</fieldset>
