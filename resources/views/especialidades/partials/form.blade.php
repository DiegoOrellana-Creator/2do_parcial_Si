@if ($errors->any())
    <div class="notice notice--error" role="alert">
        No se pudo guardar: revisa {{ $errors->count() === 1 ? 'el campo marcado' : 'los ' . $errors->count() . ' campos marcados' }}.
    </div>
@endif

<p class="form-legend-note"><span class="required-mark" aria-hidden="true">*</span> Campo obligatorio</p>

<fieldset class="form-section">
    <legend class="form-section__title">Datos de la especialidad</legend>

    <div class="form-grid">
        <div>
            <x-input-label for="nombre">Nombre <span class="required-mark" aria-hidden="true">*</span></x-input-label>
            <x-text-input id="nombre" name="nombre" type="text" maxlength="100" required
                          :value="old('nombre', $especialidad->nombre)" :aria-invalid="$errors->has('nombre') ? 'true' : null" />
            <x-input-error :messages="$errors->get('nombre')" />
        </div>

        <div>
            <x-input-label for="estado">Estado <span class="required-mark" aria-hidden="true">*</span></x-input-label>
            <select id="estado" name="estado" required class="form-input" aria-describedby="estado-ayuda" @error('estado') aria-invalid="true" @enderror>
                @foreach (\App\Models\Especialidad::ESTADOS as $valor)
                    <option value="{{ $valor }}" @selected(old('estado', $especialidad->estado) === $valor)>
                        {{ $valor === \App\Models\Especialidad::ACTIVA ? 'Activa' : 'Inactiva' }}
                    </option>
                @endforeach
            </select>
            <p id="estado-ayuda" class="field-hint">Una especialidad inactiva se conserva, pero deja de ofrecerse.</p>
            <x-input-error :messages="$errors->get('estado')" />
        </div>

        <div class="form-grid__full">
            <x-input-label for="descripcion" value="Descripción" />
            <textarea id="descripcion" name="descripcion" rows="3" maxlength="1000" class="form-input"
                      @error('descripcion') aria-invalid="true" @enderror>{{ old('descripcion', $especialidad->descripcion) }}</textarea>
            <x-input-error :messages="$errors->get('descripcion')" />
        </div>
    </div>
</fieldset>
