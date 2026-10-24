@if ($errors->any())
    <div class="notice notice--error" role="alert">
        No se pudo guardar: revisa {{ $errors->count() === 1 ? 'el campo marcado' : 'los ' . $errors->count() . ' campos marcados' }}.
    </div>
@endif

<p class="form-legend-note"><span class="required-mark" aria-hidden="true">*</span> Campo obligatorio</p>

<fieldset class="form-section">
    <legend class="form-section__title">Bloque de atención semanal</legend>

    <div class="form-grid">
        <div class="form-grid__full">
            @if ($horario->exists)
                <x-input-label value="Médico" />
                <p class="form-static">{{ $horario->medico->usuario->nombre }} {{ $horario->medico->usuario->apellido }}</p>
            @else
                <x-input-label for="id_medico">Médico <span class="required-mark" aria-hidden="true">*</span></x-input-label>
                <select id="id_medico" name="id_medico" required class="form-input" @error('id_medico') aria-invalid="true" @enderror>
                    <option value="">Selecciona un médico</option>
                    @foreach ($medicos as $medico)
                        <option value="{{ $medico->id_medico }}" @selected((int) old('id_medico', $horario->id_medico) === (int) $medico->id_medico)>
                            {{ $medico->usuario->apellido }}, {{ $medico->usuario->nombre }}{{ $medico->matricula ? ' — ' . $medico->matricula : '' }}
                        </option>
                    @endforeach
                </select>
                @if ($medicos->isEmpty())
                    <p class="field-hint">No hay médicos activos. Regístralos primero en el módulo Médicos.</p>
                @endif
                <x-input-error :messages="$errors->get('id_medico')" />
            @endif
        </div>

        <div>
            <x-input-label for="dia_semana">Día de la semana <span class="required-mark" aria-hidden="true">*</span></x-input-label>
            <select id="dia_semana" name="dia_semana" required class="form-input" @error('dia_semana') aria-invalid="true" @enderror>
                <option value="">Selecciona un día</option>
                @foreach (\App\Models\HorarioAtencion::DIAS as $numero => $nombre)
                    <option value="{{ $numero }}" @selected((int) old('dia_semana', $horario->dia_semana) === $numero)>{{ $nombre }}</option>
                @endforeach
            </select>
            <x-input-error :messages="$errors->get('dia_semana')" />
        </div>

        <div>
            <x-input-label for="duracion_cita">Duración de cada cita (minutos) <span class="required-mark" aria-hidden="true">*</span></x-input-label>
            <x-text-input id="duracion_cita" name="duracion_cita" type="number" min="5" max="240" step="5" required
                          :value="old('duracion_cita', $horario->duracion_cita)" :aria-invalid="$errors->has('duracion_cita') ? 'true' : null" />
            <x-input-error :messages="$errors->get('duracion_cita')" />
        </div>

        <div>
            <x-input-label for="hora_inicio">Hora de inicio <span class="required-mark" aria-hidden="true">*</span></x-input-label>
            <x-text-input id="hora_inicio" name="hora_inicio" type="time" required
                          :value="old('hora_inicio', \App\Models\HorarioAtencion::hhmm($horario->hora_inicio))" :aria-invalid="$errors->has('hora_inicio') ? 'true' : null" />
            <x-input-error :messages="$errors->get('hora_inicio')" />
        </div>

        <div>
            <x-input-label for="hora_final">Hora de fin <span class="required-mark" aria-hidden="true">*</span></x-input-label>
            <x-text-input id="hora_final" name="hora_final" type="time" required
                          :value="old('hora_final', \App\Models\HorarioAtencion::hhmm($horario->hora_final))" :aria-invalid="$errors->has('hora_final') ? 'true' : null" />
            <x-input-error :messages="$errors->get('hora_final')" />
        </div>
    </div>

    <p class="field-hint">Las franjas se generan desde la hora de inicio cada «duración» minutos, mientras quepan antes de la hora de fin.</p>
</fieldset>
