<x-app-layout>
    <x-slot name="header">
        <div class="page-heading">
            <div>
                <h1 class="page-title">Disponibilidad médica</h1>
                <p class="page-subtitle">Horarios libres según la atención de cada médico, sus citas y los bloqueos de su agenda</p>
            </div>
        </div>
    </x-slot>

    <div class="page">
        @if ($paciente->bloqueado)
            <div class="notice notice--error" role="alert">
                Tu cuenta tiene la solicitud de citas bloqueada. Puedes consultar la disponibilidad, pero para pedir una cita contacta con la clínica.
            </div>
        @endif

        <x-admin.card :padding="false">
            @include('disponibilidad.partials.consulta', ['accion' => route('mis-citas.disponibilidad')])
        </x-admin.card>

        @if ($resultados !== null)
            <x-admin.card :title="$especialidad->nombre" :subtitle="Str::ucfirst($consulta['fecha']->locale('es')->isoFormat('dddd, D [de] MMMM [de] YYYY'))" :padding="false">
                @forelse ($resultados as $resultado)
                    <div class="availability">
                        <div class="availability__head">
                            <p class="item-title">{{ $resultado['medico']->usuario->nombre }} {{ $resultado['medico']->usuario->apellido }}</p>
                            <p class="item-meta">
                                @if ($resultado['total'] === 0)
                                    No atiende este día.
                                @else
                                    {{ $resultado['libres']->count() }} {{ $resultado['libres']->count() === 1 ? 'horario libre' : 'horarios libres' }}
                                @endif
                            </p>
                        </div>

                        @if ($resultado['libres']->isNotEmpty())
                            <ul class="slot-list">
                                @foreach ($resultado['libres'] as $franja)
                                    <li>
                                        @if ($paciente->bloqueado)
                                            <span class="slot slot--libre">{{ $franja['inicio'] }}</span>
                                        @else
                                            <a class="slot slot--libre"
                                               href="{{ route('mis-citas.create', ['especialidad' => $especialidad->id_especialidad, 'fecha' => $consulta['fecha']->toDateString(), 'medico' => $resultado['medico']->id_medico, 'franja' => $resultado['medico']->id_medico . '|' . $franja['inicio']]) }}"
                                               title="Solicitar una cita a las {{ $franja['inicio'] }}">{{ $franja['inicio'] }}</a>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @elseif ($resultado['total'] > 0)
                            <p class="muted">No quedan horarios libres este día.</p>
                        @endif
                    </div>
                @empty
                    <div class="empty-state">
                        <p class="empty-state__title">Por ahora ningún médico atiende esta especialidad.</p>
                        <p class="empty-state__text">Prueba con otra especialidad.</p>
                    </div>
                @endforelse
            </x-admin.card>
        @elseif ($consulta['errores']->isEmpty())
            <x-admin.card>
                <p class="muted">Elige una especialidad y una fecha para ver los horarios libres. Pulsa un horario para solicitar la cita.</p>
            </x-admin.card>
        @endif
    </div>
</x-app-layout>
