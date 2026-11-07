@php
use Illuminate\Support\Carbon;

$medicoActual = $cita->medico->usuario;
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="page-heading">
            <div>
                <a href="{{ route('mis-citas.show', $cita) }}" class="back-link">← Cita {{ $cita->codigo }}</a>
                <h1 class="page-title">Reprogramar cita</h1>
                <p class="page-subtitle">
                    Actualmente: {{ Carbon::parse($cita->fecha_cita)->format('d/m/Y') }} a las {{ substr($cita->hora_inicio, 0, 5) }}
                    con {{ $medicoActual->nombre }} {{ $medicoActual->apellido }}
                </p>
            </div>
        </div>
    </x-slot>

    <div class="page">
        <x-admin.card title="1. Nueva fecha" :padding="false">
            @include('disponibilidad.partials.consulta', ['accion' => route('mis-citas.reprogramar', $cita), 'paciente' => null])
        </x-admin.card>

        @if ($resultados !== null)
            <x-admin.card title="2. Nuevo médico y horario" :subtitle="$cita->especialidad->nombre . ' · ' . Str::ucfirst($consulta['fecha']->locale('es')->isoFormat('dddd, D [de] MMMM [de] YYYY'))">
                @php($hayLibres = $resultados->contains(fn ($r) => $r['libres']->isNotEmpty()))

                @if (! $hayLibres)
                    <div class="empty-state">
                        <p class="empty-state__title">No hay horarios libres para esta especialidad en esa fecha.</p>
                        <p class="empty-state__text">Prueba con otra fecha u otro médico.</p>
                    </div>
                @else
                    <form method="POST" action="{{ route('mis-citas.reprogramar.guardar', $cita) }}" class="patient-form">
                        @csrf
                        @method('PATCH')
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
                                            <input type="radio" name="franja" value="{{ $valor }}" required @checked(old('franja') === $valor)>
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
                                    <x-input-label for="motivo_reprogramacion">Motivo de la reprogramación <span class="required-mark" aria-hidden="true">*</span></x-input-label>
                                    <textarea id="motivo_reprogramacion" name="motivo_reprogramacion" rows="2" maxlength="500" required class="form-input"
                                              @error('motivo_reprogramacion') aria-invalid="true" @enderror>{{ old('motivo_reprogramacion') }}</textarea>
                                    <p class="field-hint">La cita volverá a estado «Programada».</p>
                                </div>
                            </div>
                        </fieldset>

                        <div class="form-actions form-actions--end">
                            <a href="{{ route('mis-citas.show', $cita) }}" class="btn btn--secondary">Volver</a>
                            <x-primary-button>Reprogramar cita</x-primary-button>
                        </div>
                    </form>
                @endif
            </x-admin.card>
        @endif
    </div>
</x-app-layout>
