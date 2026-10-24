@php
use Illuminate\Support\Carbon;
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="page-heading">
            <div>
                <h1 class="page-title">Bitácora</h1>
                <p class="page-subtitle">Historial de operaciones del sistema. Solo consulta: los registros no se pueden modificar ni eliminar.</p>
            </div>
        </div>
    </x-slot>

    <div class="page">
        <x-admin.card :padding="false">
            <!-- Filtros: formulario GET normal -->
            <form method="GET" action="{{ route('bitacora.index') }}" class="search-form" role="search">
                <div class="search-form__field search-form__field--ancho">
                    <label for="q" class="form-label">Buscar</label>
                    <input id="q" name="q" type="search" value="{{ $filtros['q'] }}" maxlength="100" class="form-input" placeholder="Descripción, usuario, IP o n.º de registro">
                </div>
                <div class="search-form__field">
                    <label for="desde" class="form-label">Desde</label>
                    <input id="desde" name="desde" type="date" value="{{ $filtros['desde'] }}" class="form-input">
                </div>
                <div class="search-form__field">
                    <label for="hasta" class="form-label">Hasta</label>
                    <input id="hasta" name="hasta" type="date" value="{{ $filtros['hasta'] }}" class="form-input">
                </div>
                <div class="search-form__field">
                    <label for="filtro-usuario" class="form-label">Usuario</label>
                    <select id="filtro-usuario" name="usuario" class="form-input">
                        <option value="">Todos</option>
                        @foreach ($usuarios as $usuario)
                            <option value="{{ $usuario->id }}" @selected($filtros['usuario'] === (int) $usuario->id)>{{ $usuario->apellido }}, {{ $usuario->nombre }} ({{ $usuario->username }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="search-form__field">
                    <label for="filtro-modulo" class="form-label">Módulo</label>
                    <select id="filtro-modulo" name="modulo" class="form-input">
                        <option value="">Todos</option>
                        @foreach ($modulos as $modulo)
                            <option value="{{ $modulo }}" @selected($filtros['modulo'] === $modulo)>{{ $modulo }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="search-form__field">
                    <label for="filtro-accion" class="form-label">Acción</label>
                    <select id="filtro-accion" name="accion" class="form-input">
                        <option value="">Todas</option>
                        @foreach ($acciones as $accion)
                            <option value="{{ $accion }}" @selected($filtros['accion'] === $accion)>{{ $accion }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="search-form__field search-form__field--boton">
                    <button type="submit" class="btn btn--primary">Filtrar</button>
                    <a href="{{ route('bitacora.index') }}" class="btn btn--secondary">Limpiar</a>
                </div>
            </form>

            @if ($rangoInvertido)
                <div class="card__body">
                    <div class="notice notice--error" role="alert">La fecha «Desde» es posterior a «Hasta»: no puede haber resultados.</div>
                </div>
            @endif

            @if ($registros->isEmpty())
                <div class="empty-state">
                    <p class="empty-state__title">No hay registros en la bitácora que coincidan con los filtros.</p>
                    <p class="empty-state__text">Las operaciones se registran automáticamente a medida que los usuarios usan el sistema.</p>
                </div>
            @else
                <div class="table-scroll">
                    <table class="data-table responsive-table">
                        <caption class="sr-only">Registros de la bitácora, del más reciente al más antiguo</caption>
                        <thead>
                            <tr>
                                <th scope="col">Fecha y hora</th>
                                <th scope="col">Usuario</th>
                                <th scope="col">Acción</th>
                                <th scope="col">Módulo</th>
                                <th scope="col">Registro</th>
                                <th scope="col">Descripción</th>
                                <th scope="col">IP</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($registros as $registro)
                                <tr>
                                    <td data-label="Fecha y hora">
                                        <span class="cell-strong">{{ Carbon::parse($registro->fecha_hora)->format('d/m/Y') }}</span>
                                        <span class="cell-sub">{{ Carbon::parse($registro->fecha_hora)->format('H:i:s') }}</span>
                                    </td>
                                    <td data-label="Usuario">
                                        @if ($registro->usuario)
                                            <span class="cell-strong">{{ $registro->usuario->nombre }} {{ $registro->usuario->apellido }}</span>
                                            <span class="cell-sub">{{ $registro->usuario->username }}</span>
                                        @else
                                            <span class="muted">Sin usuario</span>
                                        @endif
                                    </td>
                                    <td data-label="Acción">{{ $registro->accion }}</td>
                                    <td data-label="Módulo">{{ $registro->modulo ?? '—' }}</td>
                                    <td data-label="Registro">{{ $registro->id_registro !== null ? '#' . $registro->id_registro : '—' }}</td>
                                    <td data-label="Descripción" class="pre-line">{{ $registro->detalle ?? '—' }}</td>
                                    <td data-label="IP">{{ $registro->ip ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="list-footer">
                    <p class="list-footer__summary">Mostrando {{ $registros->firstItem() }}–{{ $registros->lastItem() }} de {{ $registros->total() }} {{ $registros->total() === 1 ? 'registro' : 'registros' }}</p>
                    {{ $registros->links('pagination.clinica') }}
                </div>
            @endif
        </x-admin.card>
    </div>
</x-app-layout>
