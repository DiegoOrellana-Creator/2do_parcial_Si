@php
use Illuminate\Support\Carbon;
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="page-heading">
            <div>
                <h1 class="page-title">Mis citas</h1>
                <p class="page-subtitle">Tus citas en la Clínica Santa Cruz</p>
            </div>

            @unless ($paciente->bloqueado)
                <div class="page-heading__actions">
                    <a href="{{ route('mis-citas.create') }}" class="btn btn--primary">Solicitar cita</a>
                </div>
            @endunless
        </div>
    </x-slot>

    <div class="page">
        <x-admin.flash />

        @if ($paciente->bloqueado)
            <div class="notice notice--error" role="alert">
                Tu cuenta tiene la solicitud de citas bloqueada. Contacta con la clínica para reservar una cita.
            </div>
        @endif

        @foreach (['Próximas citas' => $proximas, 'Citas anteriores' => $anteriores] as $titulo => $citas)
            <x-admin.card :title="$titulo" :padding="false">
                @if ($citas->isEmpty())
                    <div class="empty-state">
                        <p class="empty-state__title">{{ $loop->first ? 'No tienes citas próximas.' : 'No tienes citas anteriores.' }}</p>
                    </div>
                @else
                    <div class="table-scroll">
                        <table class="data-table responsive-table">
                            <caption class="sr-only">{{ $titulo }}</caption>
                            <thead>
                                <tr>
                                    <th scope="col">Fecha y hora</th>
                                    <th scope="col">Médico</th>
                                    <th scope="col">Especialidad</th>
                                    <th scope="col">Estado</th>
                                    <th scope="col"><span class="sr-only">Acciones</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($citas as $cita)
                                    <tr>
                                        <td data-label="Fecha y hora">
                                            <span class="cell-strong">{{ Carbon::parse($cita->fecha_cita)->format('d/m/Y') }} · {{ substr($cita->hora_inicio, 0, 5) }}–{{ substr($cita->hora_fin, 0, 5) }}</span>
                                            <span class="cell-sub">{{ $cita->codigo }}</span>
                                        </td>
                                        <td data-label="Médico">{{ $cita->medico->usuario->nombre }} {{ $cita->medico->usuario->apellido }}</td>
                                        <td data-label="Especialidad">{{ $cita->especialidad->nombre }}</td>
                                        <td data-label="Estado"><x-admin.status-badge :status="$cita->estado" /></td>
                                        <td class="cell-actions">
                                            <a href="{{ route('mis-citas.show', $cita) }}" class="btn btn--secondary btn--sm">Ver<span class="sr-only"> cita {{ $cita->codigo }}</span></a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if (method_exists($citas, 'links') && $citas->hasPages())
                        <div class="list-footer">
                            {{ $citas->links('pagination.clinica') }}
                        </div>
                    @endif
                @endif
            </x-admin.card>
        @endforeach
    </div>
</x-app-layout>
