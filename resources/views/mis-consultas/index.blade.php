@php
use Illuminate\Support\Carbon;
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="page-heading">
            <div>
                <h1 class="page-title">Mis citas</h1>
                <p class="page-subtitle">Citas asignadas a tu agenda</p>
            </div>
            <div class="page-heading__actions">
                <a href="{{ route('agenda.index') }}" class="btn btn--secondary">Ver mi agenda</a>
            </div>
        </div>
    </x-slot>

    <div class="page">
        <x-admin.card :padding="false">
            <form method="GET" action="{{ route('consultas.index') }}" class="search-form">
                <div class="search-form__field">
                    <label for="estado" class="form-label">Estado</label>
                    <select id="estado" name="estado" class="form-input">
                        <option value="">Todos</option>
                        @foreach (\App\Models\Cita::ESTADOS as $valor)
                            <option value="{{ $valor }}" @selected($estado === $valor)>{{ $valor }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="search-form__field search-form__field--boton">
                    <button type="submit" class="btn btn--primary">Filtrar</button>
                    <a href="{{ route('consultas.index') }}" class="btn btn--secondary">Limpiar</a>
                </div>
            </form>
        </x-admin.card>

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
                                    <th scope="col">Paciente</th>
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
                                        <td data-label="Paciente">{{ $cita->paciente->usuario->nombre }} {{ $cita->paciente->usuario->apellido }}</td>
                                        <td data-label="Especialidad">{{ $cita->especialidad->nombre }}</td>
                                        <td data-label="Estado"><x-admin.status-badge :status="$cita->estado" /></td>
                                        <td class="cell-actions">
                                            <a href="{{ route('consultas.show', $cita) }}" class="btn btn--secondary btn--sm">Ver<span class="sr-only"> cita {{ $cita->codigo }}</span></a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="list-footer">
                        <p class="list-footer__summary">Mostrando {{ $citas->firstItem() }}–{{ $citas->lastItem() }} de {{ $citas->total() }} {{ $citas->total() === 1 ? 'cita' : 'citas' }}</p>
                        {{ $citas->links('pagination.clinica') }}
                    </div>
                @endif
            </x-admin.card>
        @endforeach
    </div>
</x-app-layout>
