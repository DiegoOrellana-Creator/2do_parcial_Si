<x-app-layout>
    <x-slot name="header">
        <div class="page-heading">
            <div>
                <a href="{{ route('mis-citas.index') }}" class="back-link">← Mis citas</a>
                <h1 class="page-title">Solicitar cita</h1>
                <p class="page-subtitle">1. Elige especialidad y fecha · 2. Elige médico y horario, y confirma la solicitud</p>
            </div>
        </div>
    </x-slot>

    <div class="page">
        @if ($paciente->bloqueado)
            <div class="notice notice--error" role="alert">
                Tu cuenta tiene la solicitud de citas bloqueada. Contacta con la clínica para reservar una cita.
            </div>
        @endif

        <x-admin.card title="1. Especialidad y fecha" :padding="false">
            @include('disponibilidad.partials.consulta', ['accion' => route('mis-citas.create')])
        </x-admin.card>

        @if ($resultados !== null && ! $paciente->bloqueado)
            <x-admin.card title="2. Médico y horario" :subtitle="$especialidad->nombre . ' · ' . Str::ucfirst($consulta['fecha']->locale('es')->isoFormat('dddd, D [de] MMMM [de] YYYY'))">
                @php($hayLibres = $resultados->contains(fn ($r) => $r['libres']->isNotEmpty()))

                @if (! $hayLibres)
                    <div class="empty-state">
                        <p class="empty-state__title">No hay horarios libres para esta especialidad en esa fecha.</p>
                        <p class="empty-state__text">Prueba con otra fecha u otro médico.</p>
                    </div>
                @else
                    <form method="POST" action="{{ route('mis-citas.store') }}" class="patient-form">
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
                        </fieldset>

                        <fieldset class="form-section">
                            <legend class="form-section__title">Motivo</legend>

                            <div class="form-grid">
                                <div class="form-grid__full">
                                    <x-input-label for="motivo" value="Motivo de la consulta (opcional)" />
                                    <textarea id="motivo" name="motivo" rows="2" maxlength="1000" class="form-input" @error('motivo') aria-invalid="true" @enderror>{{ old('motivo') }}</textarea>
                                    <x-input-error :messages="$errors->get('motivo')" />
                                </div>
                            </div>
                        </fieldset>

                        <p class="field-hint">La cita quedará a tu nombre en estado «Programada».</p>

                        <div class="form-actions form-actions--end">
                            <a href="{{ route('mis-citas.index') }}" class="btn btn--secondary">Cancelar</a>
                            <x-primary-button>Solicitar cita</x-primary-button>
                        </div>
                    </form>
                @endif
            </x-admin.card>
        @endif
    </div>
</x-app-layout>
