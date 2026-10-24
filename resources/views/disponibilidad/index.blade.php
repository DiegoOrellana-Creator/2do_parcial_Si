<x-app-layout>
    <x-slot name="header">
        <div class="page-heading">
            <div>
                <h1 class="page-title">Disponibilidad de citas</h1>
                <p class="page-subtitle">Franjas libres según los horarios de atención, las citas registradas y los bloqueos de agenda</p>
            </div>
        </div>
    </x-slot>

    <div class="page">
        <x-admin.card :padding="false">
            @include('disponibilidad.partials.consulta', ['accion' => route('disponibilidad.index')])
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
                                    {{ $resultado['libres']->count() }} de {{ $resultado['total'] }} {{ $resultado['total'] === 1 ? 'franja libre' : 'franjas libres' }}
                                @endif
                            </p>
                        </div>

                        @if ($resultado['libres']->isNotEmpty())
                            <ul class="slot-list">
                                @foreach ($resultado['libres'] as $franja)
                                    <li>
                                        @can('citas.crear')
                                            <a class="slot slot--libre"
                                               href="{{ route('citas.create', ['especialidad' => $especialidad->id_especialidad, 'fecha' => $consulta['fecha']->toDateString(), 'medico' => $resultado['medico']->id_medico, 'franja' => $resultado['medico']->id_medico . '|' . $franja['inicio']]) }}"
                                               title="Registrar una cita a las {{ $franja['inicio'] }}">{{ $franja['inicio'] }}</a>
                                        @else
                                            <span class="slot slot--libre">{{ $franja['inicio'] }}</span>
                                        @endcan
                                    </li>
                                @endforeach
                            </ul>
                        @elseif ($resultado['total'] > 0)
                            <p class="muted">No quedan franjas libres este día.</p>
                        @endif
                    </div>
                @empty
                    <div class="empty-state">
                        <p class="empty-state__title">Ningún médico activo ejerce esta especialidad.</p>
                        <p class="empty-state__text">Asigna la especialidad a un médico en el módulo Médicos.</p>
                    </div>
                @endforelse
            </x-admin.card>
        @elseif ($consulta['errores']->isEmpty())
            <x-admin.card>
                <p class="muted">Elige una especialidad y una fecha para ver las franjas libres.</p>
            </x-admin.card>
        @endif
    </div>
</x-app-layout>
