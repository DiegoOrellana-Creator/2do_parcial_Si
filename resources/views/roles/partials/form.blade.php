@php
$seleccionados = collect(old('permisos', $marcados))->map(fn ($id) => (int) $id)->all();
$editable = ! $rol->exists || $rol->permisosEditables();
@endphp

@if ($errors->any())
    <div class="notice notice--error" role="alert">
        No se pudo guardar: revisa {{ $errors->count() === 1 ? 'el campo marcado' : 'los ' . $errors->count() . ' campos marcados' }}.
    </div>
@endif

<fieldset class="form-section">
    <legend class="form-section__title">Datos del rol</legend>

    <div class="form-grid">
        <div class="form-grid__full">
            @if ($rol->exists && $rol->esBase())
                <x-input-label value="Nombre" />
                <p class="form-static">{{ $rol->nombre }}</p>
                <p class="field-hint">Es un rol del sistema: su nombre no se puede cambiar.</p>
            @else
                <x-input-label for="nombre">Nombre <span class="required-mark" aria-hidden="true">*</span></x-input-label>
                <x-text-input id="nombre" name="nombre" type="text" maxlength="50" required
                              :value="old('nombre', $rol->nombre)" :aria-invalid="$errors->has('nombre') ? 'true' : null" />
                <x-input-error :messages="$errors->get('nombre')" />
            @endif
        </div>

        <div class="form-grid__full">
            <x-input-label for="descripcion" value="Descripción" />
            <textarea id="descripcion" name="descripcion" rows="2" maxlength="500" class="form-input" @error('descripcion') aria-invalid="true" @enderror>{{ old('descripcion', $rol->descripcion) }}</textarea>
            <x-input-error :messages="$errors->get('descripcion')" />
        </div>
    </div>
</fieldset>

<fieldset class="form-section">
    <legend class="form-section__title">Permisos</legend>

    @unless ($editable)
        <p class="notice notice--info">
            {{ $rol->nombre === \App\Models\Rol::ADMINISTRADOR
                ? 'El administrador tiene todos los permisos: no se pueden quitar.'
                : 'Este rol solo identifica un perfil y no tiene permisos de gestión.' }}
        </p>
    @endunless

    @foreach ($modulos as $modulo => $permisos)
        <p class="item-title permiso-modulo">{{ $modulo }}</p>
        <div class="checkbox-grid">
            @foreach ($permisos as $permiso)
                <label class="checkbox-option">
                    <input type="checkbox" name="permisos[]" value="{{ $permiso->id_permiso }}" @checked(in_array((int) $permiso->id_permiso, $seleccionados, true)) @disabled(! $editable)>
                    <span>{{ $permiso->nombre }}</span>
                </label>
            @endforeach
        </div>
    @endforeach

    <x-input-error :messages="$errors->get('permisos')" />
    @foreach ($errors->get('permisos.*') as $mensajes)
        <x-input-error :messages="$mensajes" />
    @endforeach
</fieldset>
