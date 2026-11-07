<x-app-layout>
    <x-slot name="header">
        <div class="page-heading">
            <div>
                <a href="{{ route('agenda.bloqueos.index') }}" class="back-link">← Bloqueos de agenda</a>
                <h1 class="page-title">Registrar bloqueo</h1>
                <p class="page-subtitle">No se puede bloquear un periodo que tenga citas pendientes</p>
            </div>
        </div>
    </x-slot>

    <div class="page">
        <x-admin.card>
            <form method="POST" action="{{ route('agenda.bloqueos.store') }}" class="patient-form">
                @csrf

                @if ($errors->any())
                    <div class="notice notice--error" role="alert">
                        No se pudo guardar: revisa {{ $errors->count() === 1 ? 'el campo marcado' : 'los ' . $errors->count() . ' campos marcados' }}.
                    </div>
                @endif

                <p class="form-legend-note"><span class="required-mark" aria-hidden="true">*</span> Campo obligatorio</p>

                <fieldset class="form-section">
                    <legend class="form-section__title">Periodo sin atención</legend>

                    <div class="form-grid">
                        <div class="form-grid__full">
                            <x-input-label for="id_medico">Médico <span class="required-mark" aria-hidden="true">*</span></x-input-label>
                            <select id="id_medico" name="id_medico" required class="form-input" @error('id_medico') aria-invalid="true" @enderror>
                                <option value="">Selecciona un médico</option>
                                @foreach ($medicos as $medico)
                                    <option value="{{ $medico->id_medico }}" @selected((int) old('id_medico', $idMedico) === (int) $medico->id_medico)>
                                        {{ $medico->usuario->apellido }}, {{ $medico->usuario->nombre }}
                                    </option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('id_medico')" />
                        </div>

                        <div class="form-grid__full">
                            <x-input-label for="fecha">Fecha <span class="required-mark" aria-hidden="true">*</span></x-input-label>
                            <x-text-input id="fecha" name="fecha" type="date" required :min="now()->toDateString()"
                                          :value="old('fecha')" :aria-invalid="$errors->has('fecha') ? 'true' : null" />
                            <x-input-error :messages="$errors->get('fecha')" />
                        </div>

                        <div>
                            <x-input-label for="hora_inicio">Hora de inicio <span class="required-mark" aria-hidden="true">*</span></x-input-label>
                            <x-text-input id="hora_inicio" name="hora_inicio" type="time" required
                                          :value="old('hora_inicio')" :aria-invalid="$errors->has('hora_inicio') ? 'true' : null" />
                            <x-input-error :messages="$errors->get('hora_inicio')" />
                        </div>

                        <div>
                            <x-input-label for="hora_final">Hora de fin <span class="required-mark" aria-hidden="true">*</span></x-input-label>
                            <x-text-input id="hora_final" name="hora_final" type="time" required
                                          :value="old('hora_final')" :aria-invalid="$errors->has('hora_final') ? 'true' : null" />
                            <x-input-error :messages="$errors->get('hora_final')" />
                        </div>

                        <div class="form-grid__full">
                            <x-input-label for="motivo">Motivo <span class="required-mark" aria-hidden="true">*</span></x-input-label>
                            <textarea id="motivo" name="motivo" rows="2" maxlength="500" required class="form-input"
                                      @error('motivo') aria-invalid="true" @enderror>{{ old('motivo') }}</textarea>
                            <x-input-error :messages="$errors->get('motivo')" />
                        </div>
                    </div>
                </fieldset>

                <div class="form-actions form-actions--end">
                    <a href="{{ route('agenda.bloqueos.index') }}" class="btn btn--secondary">Cancelar</a>
                    <x-primary-button>Registrar bloqueo</x-primary-button>
                </div>
            </form>
        </x-admin.card>
    </div>
</x-app-layout>
