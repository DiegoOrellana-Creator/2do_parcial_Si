@php
use App\Models\HorarioAtencion;
use Illuminate\Support\Carbon;
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="page-heading">
            <div>
                <a href="{{ route('agenda.index') }}" class="back-link">← Agenda médica</a>
                <h1 class="page-title">Bloqueos de agenda</h1>
                <p class="page-subtitle">Periodos en los que un médico no atiende: esas franjas no se ofrecen para citas</p>
            </div>

            <a href="{{ route('agenda.bloqueos.create', $idMedico ? ['medico' => $idMedico] : []) }}" class="btn btn--primary">
                <x-admin.icon name="plus" class="icon--sm" />
                Registrar bloqueo
            </a>
        </div>
    </x-slot>

    <div class="page">
        <x-admin.flash />

        <x-admin.card :padding="false">
            <form method="GET" action="{{ route('agenda.bloqueos.index') }}" class="search-form" role="search">
                <div class="search-form__field search-form__field--ancho">
                    <label for="filtro-medico" class="form-label">Médico</label>
                    <select id="filtro-medico" name="medico" class="form-input">
                        <option value="">Todos los médicos</option>
                        @foreach ($medicos as $medico)
                            <option value="{{ $medico->id_medico }}" @selected($idMedico === (int) $medico->id_medico)>
                                {{ $medico->usuario->apellido }}, {{ $medico->usuario->nombre }}{{ $medico->usuario->estado !== \App\Models\User::ESTADO_ACTIVO ? ' (inactivo)' : '' }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="search-form__field">
                    <label for="desde" class="form-label">Desde</label>
                    <input id="desde" name="desde" type="date" value="{{ $desde }}" class="form-input">
                </div>
                <div class="search-form__field search-form__field--boton">
                    <button type="submit" class="btn btn--primary">Filtrar</button>
                    <a href="{{ route('agenda.bloqueos.index') }}" class="btn btn--secondary">Limpiar</a>
                </div>
            </form>

            @if ($bloqueos->isEmpty())
                <div class="empty-state">
                    <p class="empty-state__title">No hay bloqueos de agenda con esos filtros.</p>
                    <p class="empty-state__text">Por defecto se muestran desde hoy; cambia «Desde» para ver los anteriores.</p>
                </div>
            @else
                <div class="table-scroll">
                    <table class="data-table responsive-table">
                        <caption class="sr-only">Bloqueos de agenda</caption>
                        <thead>
                            <tr>
                                <th scope="col">Fecha y hora</th>
                                <th scope="col">Médico</th>
                                <th scope="col">Motivo</th>
                                <th scope="col"><span class="sr-only">Acciones</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($bloqueos as $bloqueo)
                                @php($periodo = Carbon::parse($bloqueo->fecha)->format('d/m/Y') . ' · ' . HorarioAtencion::hhmm($bloqueo->hora_inicio) . '–' . HorarioAtencion::hhmm($bloqueo->hora_final))
                                <tr>
                                    <td data-label="Fecha y hora" class="cell-strong">{{ $periodo }}</td>
                                    <td data-label="Médico">{{ $bloqueo->medico->usuario->apellido }}, {{ $bloqueo->medico->usuario->nombre }}</td>
                                    <td data-label="Motivo" class="cell-wrap">{{ $bloqueo->motivo ?? '—' }}</td>
                                    <td class="cell-actions">
                                        @if ($bloqueo->haFinalizado())
                                            <span class="muted">Finalizado</span>
                                        @else
                                            {{-- Sin JavaScript: el botón abre la ventana; el bloqueo se quita al confirmar --}}
                                            <x-modal name="quitar-bloqueo-{{ $bloqueo->id_bloqueo }}" maxWidth="md" trigger-class="btn btn--secondary btn--sm">
                                                <x-slot name="trigger">Quitar</x-slot>

                                                <form method="POST" action="{{ route('agenda.bloqueos.destroy', $bloqueo) }}" class="modal__body">
                                                    @csrf
                                                    @method('DELETE')

                                                    <h2 class="section-title">¿Quitar este bloqueo?</h2>
                                                    <p class="section-text">{{ $periodo }}. Esas franjas volverán a estar disponibles para citas.</p>

                                                    <div class="modal__actions">
                                                        <a href="{{ route('agenda.bloqueos.index', request()->query()) }}" class="btn btn--secondary">Volver</a>
                                                        <x-danger-button>Quitar bloqueo</x-danger-button>
                                                    </div>
                                                </form>
                                            </x-modal>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="list-footer">
                    <p class="list-footer__summary">Mostrando {{ $bloqueos->firstItem() }}–{{ $bloqueos->lastItem() }} de {{ $bloqueos->total() }} {{ $bloqueos->total() === 1 ? 'bloqueo' : 'bloqueos' }}</p>
                    {{ $bloqueos->links('pagination.clinica') }}
                </div>
            @endif
        </x-admin.card>
    </div>
</x-app-layout>
