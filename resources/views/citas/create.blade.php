<x-app-layout>
    <x-slot name="header">
        <div class="page-heading">
            <div>
                <a href="{{ route('citas.index') }}" class="back-link">← Gestión de citas</a>
                <h1 class="page-title">Registrar cita</h1>
                <p class="page-subtitle">1. Consulta la disponibilidad · 2. Elige la franja, el paciente y registra la cita</p>
            </div>
        </div>
    </x-slot>

    <div class="page">
        <x-admin.card title="1. Disponibilidad" :padding="false">
            @include('disponibilidad.partials.consulta', ['accion' => route('citas.create'), 'paciente' => $idPaciente])
        </x-admin.card>

        @if ($resultados !== null)
            <x-admin.card title="2. Datos de la cita" :subtitle="$especialidad->nombre . ' · ' . Str::ucfirst($consulta['fecha']->locale('es')->isoFormat('dddd, D [de] MMMM [de] YYYY'))">
                @php($hayLibres = $resultados->contains(fn ($r) => $r['libres']->isNotEmpty()))

                @if (! $hayLibres)
                    <div class="empty-state">
                        <p class="empty-state__title">No hay franjas libres para esta especialidad en esa fecha.</p>
                        <p class="empty-state__text">Prueba con otra fecha u otro médico.</p>
                    </div>
                @else
                    <form method="POST" action="{{ route('citas.store') }}" class="patient-form">
                        @csrf
                        <input type="hidden" name="id_especialidad" value="{{ $especialidad->id_especialidad }}">
                        <input type="hidden" name="fecha" value="{{ $consulta['fecha']->toDateString() }}">

                        @if ($errors->any())
                            <div class="notice notice--error" role="alert">
                                <ul>
                                    @foreach ($errors->all() as $mensaje)
                                        <li>{{ $mensaje }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        <fieldset class="form-section" @error('franja') aria-invalid="true" @enderror>
                            <legend class="form-section__title">Médico y hora <span class="required-mark" aria-hidden="true">*</span></legend>

                            @foreach ($resultados as $resultado)
                                @continue($resultado['libres']->isEmpty())
                                <p class="item-title availability__name">{{ $resultado['medico']->usuario->nombre }} {{ $resultado['medico']->usuario->apellido }}</p>
                                <div class="slot-list">
                                    @foreach ($resultado['libres'] as $franja)
                                        @php($valor = $resultado['medico']->id_medico . '|' . $franja['inicio'])
                                        <label class="slot-option">
                                            <input type="radio" name="franja" value="{{ $valor }}" required @checked(old('franja', request()->query('franja')) === $valor)>
                                            <span class="slot slot--libre">{{ $franja['inicio'] }}–{{ $franja['fin'] }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            @endforeach
                            <x-input-error :messages="$errors->get('franja')" />
                        </fieldset>

                        <fieldset class="form-section">
                            <legend class="form-section__title">Paciente y motivo</legend>

                            <div class="form-grid">
                                <div class="form-grid__full">
                                    <x-input-label for="id_paciente">Paciente <span class="required-mark" aria-hidden="true">*</span></x-input-label>
                                    <select id="id_paciente" name="id_paciente" required class="form-input" @error('id_paciente') aria-invalid="true" @enderror>
                                        <option value="">Selecciona el paciente</option>
                                        @foreach ($pacientes as $paciente)
                                            <option value="{{ $paciente->id_paciente }}" @selected((int) old('id_paciente', $idPaciente) === (int) $paciente->id_paciente)>
                                                {{ $paciente->usuario->apellido }}, {{ $paciente->usuario->nombre }}{{ $paciente->usuario->ci ? ' — CI ' . $paciente->usuario->ci : '' }}{{ $paciente->nro_ficha ? ' — ' . $paciente->nro_ficha : '' }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <p class="field-hint">Solo se muestran pacientes con la cuenta activa y sin bloqueo.</p>
                                    <x-input-error :messages="$errors->get('id_paciente')" />
                                </div>

                                <div class="form-grid__full">
                                    <x-input-label for="motivo" value="Motivo de la consulta" />
                                    <textarea id="motivo" name="motivo" rows="2" maxlength="1000" class="form-input" @error('motivo') aria-invalid="true" @enderror>{{ old('motivo') }}</textarea>
                                    <x-input-error :messages="$errors->get('motivo')" />
                                </div>
                            </div>
                        </fieldset>

                        <div class="form-actions form-actions--end">
                            <a href="{{ route('citas.index') }}" class="btn btn--secondary">Cancelar</a>
                            <x-primary-button>Registrar cita</x-primary-button>
                        </div>
                    </form>
                @endif
            </x-admin.card>
        @endif
    </div>
</x-app-layout>
