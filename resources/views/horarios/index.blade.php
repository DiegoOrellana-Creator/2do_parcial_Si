<x-app-layout>
    <x-slot name="header">
        <div class="page-heading">
            <div>
                <h1 class="page-title">Horarios de atención</h1>
                <p class="page-subtitle">Bloques semanales en los que cada médico atiende</p>
            </div>

            @can('horarios.crear')
                <a href="{{ route('horarios.create', $idMedico ? ['medico' => $idMedico] : []) }}" class="btn btn--primary">
                    <x-admin.icon name="plus" class="icon--sm" />
                    Registrar horario
                </a>
            @endcan
        </div>
    </x-slot>

    <div class="page">
        <x-admin.flash />

        <x-admin.card :padding="false">
            <!-- Filtros: formulario GET normal -->
            <form method="GET" action="{{ route('horarios.index') }}" class="search-form" role="search">
                <label for="filtro-medico" class="sr-only">Médico</label>
                <select id="filtro-medico" name="medico" class="form-input search-form__input">
                    <option value="">Todos los médicos</option>
                    @foreach ($medicos as $medico)
                        <option value="{{ $medico->id_medico }}" @selected($idMedico === (int) $medico->id_medico)>
                            {{ $medico->usuario->apellido }}, {{ $medico->usuario->nombre }}{{ $medico->usuario->estado !== \App\Models\User::ESTADO_ACTIVO ? ' (inactivo)' : '' }}
                        </option>
                    @endforeach
                </select>

                <label for="filtro-dia" class="sr-only">Día</label>
                <select id="filtro-dia" name="dia" class="form-input search-form__select">
                    <option value="">Todos los días</option>
                    @foreach (\App\Models\HorarioAtencion::DIAS as $numero => $nombre)
                        <option value="{{ $numero }}" @selected($dia === $numero)>{{ $nombre }}</option>
                    @endforeach
                </select>

                <button type="submit" class="btn btn--primary">Filtrar</button>
                @if ($idMedico || $dia)
                    <a href="{{ route('horarios.index') }}" class="btn btn--secondary">Limpiar</a>
                @endif
            </form>

            @if ($horarios->isEmpty())
                <div class="empty-state">
                    <p class="empty-state__title">{{ $idMedico || $dia ? 'No hay horarios con esos filtros.' : 'Todavía no hay horarios de atención registrados.' }}</p>
                    @can('horarios.crear')
                        <p class="empty-state__text">Usa «Registrar horario» para definir cuándo atiende cada médico.</p>
                    @endcan
                </div>
            @else
                <div class="table-scroll">
                    <table class="data-table responsive-table">
                        <caption class="sr-only">Horarios de atención</caption>
                        <thead>
                            <tr>
                                <th scope="col">Médico</th>
                                <th scope="col">Día</th>
                                <th scope="col">Horario</th>
                                <th scope="col">Duración</th>
                                <th scope="col"><span class="sr-only">Acciones</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($horarios as $horario)
                                <tr>
                                    <td data-label="Médico">
                                        <span class="cell-strong">{{ $horario->medico->usuario->apellido }}, {{ $horario->medico->usuario->nombre }}</span>
                                        @if ($horario->medico->usuario->estado !== \App\Models\User::ESTADO_ACTIVO)
                                            <span class="cell-sub">Médico inactivo</span>
                                        @endif
                                    </td>
                                    <td data-label="Día">{{ $horario->nombreDia() }}</td>
                                    <td data-label="Horario">{{ \App\Models\HorarioAtencion::hhmm($horario->hora_inicio) }}–{{ \App\Models\HorarioAtencion::hhmm($horario->hora_final) }}</td>
                                    <td data-label="Duración">{{ $horario->duracion_cita ? $horario->duracion_cita . ' min' : 'Sin definir' }}</td>
                                    <td class="cell-actions">
                                        @can('horarios.editar')
                                            <a href="{{ route('horarios.edit', $horario) }}" class="btn btn--secondary btn--sm">
                                                Editar<span class="sr-only"> horario del {{ $horario->nombreDia() }}</span>
                                            </a>
                                        @endcan
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="list-footer">
                    <p class="list-footer__summary">Mostrando {{ $horarios->firstItem() }}–{{ $horarios->lastItem() }} de {{ $horarios->total() }} {{ $horarios->total() === 1 ? 'horario' : 'horarios' }}</p>
                    {{ $horarios->links('pagination.clinica') }}
                </div>
            @endif
        </x-admin.card>
    </div>
</x-app-layout>
