@php
use Illuminate\Support\Carbon;

$usuario = $paciente->usuario;
$info = $paciente->informacionMedica;
$nacimiento = $usuario->fecha_nacimiento ? Carbon::parse($usuario->fecha_nacimiento) : null;
$valor = fn ($dato) => filled($dato) ? $dato : null;
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="page-heading">
            <div>
                <a href="{{ route('pacientes.index') }}" class="back-link">← Pacientes</a>
                <h1 class="page-title">{{ $usuario->nombre }} {{ $usuario->apellido }}</h1>
                <div class="page-heading__meta">
                    @include('pacientes.partials.estado', ['paciente' => $paciente])
                    @if ($paciente->nro_ficha)
                        <span class="page-subtitle">Ficha {{ $paciente->nro_ficha }}</span>
                    @endif
                </div>
            </div>

            <div class="page-heading__actions">
                @can('pacientes.editar')
                    <a href="{{ route('pacientes.edit', $paciente) }}" class="btn btn--primary">Editar</a>
                @endcan

                @can('pacientes.desbloquear')
                    @if ($paciente->bloqueado)
                        {{-- Sin JavaScript: el botón abre la ventana; el desbloqueo se aplica al confirmar --}}
                        <x-modal name="confirmar-desbloqueo" maxWidth="md" trigger-class="btn btn--secondary">
                            <x-slot name="trigger">Desbloquear</x-slot>

                            <form method="POST" action="{{ route('pacientes.desbloquear', $paciente) }}" class="modal__body">
                                @csrf
                                @method('PATCH')

                                <h2 class="section-title">¿Desbloquear a {{ $usuario->nombre }} {{ $usuario->apellido }}?</h2>
                                <p class="section-text">
                                    Podrá volver a solicitar citas y su contador de inasistencias volverá a 0.
                                    Sus citas y sus bloqueos anteriores se conservan.
                                </p>

                                <div class="modal__actions">
                                    <a href="{{ route('pacientes.show', $paciente) }}" class="btn btn--secondary">Volver</a>
                                    <x-primary-button>Desbloquear</x-primary-button>
                                </div>
                            </form>
                        </x-modal>
                    @endif
                @endcan
            </div>
        </div>
    </x-slot>

    <div class="page">
        <x-admin.flash />

        @if ($paciente->bloqueado)
            <div class="notice notice--error" role="alert">
                Este paciente está bloqueado por inasistencias ({{ $paciente->inasistencia }}) y no puede registrar citas. Solo el administrador puede desbloquearlo.
            </div>
        @endif

        <div class="dashboard-grid dashboard-grid--halves">
            <x-admin.card title="Datos personales">
                <dl class="detail-list">
                    <div><dt>Nombre completo</dt><dd>{{ $usuario->nombre }} {{ $usuario->apellido }}</dd></div>
                    <div><dt>Carnet de identidad</dt><dd>{{ $valor($usuario->ci) ?? 'No registrado' }}</dd></div>
                    <div>
                        <dt>Fecha de nacimiento</dt>
                        <dd>
                            @if ($nacimiento)
                                {{ $nacimiento->format('d/m/Y') }} ({{ $nacimiento->age }} {{ $nacimiento->age === 1 ? 'año' : 'años' }})
                            @else
                                No registrada
                            @endif
                        </dd>
                    </div>
                    <div><dt>Sexo</dt><dd>{{ $valor($usuario->sexo) ?? 'Sin especificar' }}</dd></div>
                    <div><dt>Teléfono</dt><dd>{{ $valor($usuario->telefono) ?? 'No registrado' }}</dd></div>
                    <div><dt>Dirección</dt><dd>{{ $valor($usuario->direccion) ?? 'No registrada' }}</dd></div>
                </dl>
            </x-admin.card>

            <x-admin.card title="Cuenta y ficha clínica">
                <dl class="detail-list">
                    <div><dt>Correo electrónico</dt><dd>{{ $usuario->email }}</dd></div>
                    <div><dt>Nombre de usuario</dt><dd>{{ $usuario->username }}</dd></div>
                    <div><dt>Estado de la cuenta</dt><dd>{{ $usuario->estado }}</dd></div>
                    <div><dt>Número de ficha</dt><dd>{{ $valor($paciente->nro_ficha) ?? 'Sin asignar' }}</dd></div>
                    <div><dt>Inasistencias</dt><dd>{{ $paciente->inasistencia }}</dd></div>
                    <div><dt>Bloqueado</dt><dd>{{ $paciente->bloqueado ? 'Sí' : 'No' }}</dd></div>
                    @if ($usuario->created_at)
                        <div><dt>Registrado el</dt><dd>{{ $usuario->created_at->format('d/m/Y H:i') }}</dd></div>
                    @endif
                </dl>
            </x-admin.card>

            <x-admin.card title="Información médica">
                @if ($info)
                    <dl class="detail-list">
                        <div><dt>Tipo de sangre</dt><dd>{{ $valor($info->tipo_sangre) ?? 'Sin especificar' }}</dd></div>
                        <div><dt>Alergias</dt><dd class="pre-line">{{ $valor($info->alergias) ?? 'Ninguna registrada' }}</dd></div>
                        <div><dt>Enfermedades crónicas</dt><dd class="pre-line">{{ $valor($info->enfermedades_cronicas) ?? 'Ninguna registrada' }}</dd></div>
                        <div><dt>Medicamentos actuales</dt><dd class="pre-line">{{ $valor($info->medicamentos) ?? 'Ninguno registrado' }}</dd></div>
                        <div><dt>Antecedentes</dt><dd class="pre-line">{{ $valor($info->antecedentes) ?? 'Ninguno registrado' }}</dd></div>
                    </dl>
                @else
                    <p class="muted">No hay información médica registrada.</p>
                @endif
            </x-admin.card>

            <x-admin.card title="Contactos de emergencia" :padding="false">
                @forelse ($paciente->contactosEmergencia->sortBy('id_contacto') as $contacto)
                    <div class="contact-item">
                        <p class="item-title">{{ $contacto->nombre }}</p>
                        <p class="item-meta">
                            {{ $valor($contacto->parentesco) ?? 'Parentesco no indicado' }}
                            · {{ $valor($contacto->telefono) ?? 'Sin teléfono' }}
                        </p>
                    </div>
                @empty
                    <p class="muted card__body">No hay contactos de emergencia registrados.</p>
                @endforelse
            </x-admin.card>
        </div>

        <x-admin.card title="Historial de citas"
                      :subtitle="$totalCitas > $citas->count() ? 'Últimas ' . $citas->count() . ' de ' . $totalCitas : null"
                      :padding="false">
            @if ($citas->isEmpty())
                <p class="muted card__body">El paciente todavía no tiene citas registradas.</p>
            @else
                <div class="card__body">
                    <a href="{{ route('pacientes.citas', $paciente) }}" class="btn btn--secondary btn--sm">Ver historial completo ({{ $totalCitas }})</a>
                </div>
                <div class="table-scroll">
                    <table class="data-table responsive-table">
                        <caption class="sr-only">Historial de citas del paciente</caption>
                        <thead>
                            <tr>
                                <th scope="col">Código</th>
                                <th scope="col">Fecha</th>
                                <th scope="col">Hora</th>
                                <th scope="col">Médico</th>
                                <th scope="col">Especialidad</th>
                                <th scope="col">Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($citas as $cita)
                                <tr>
                                    <td data-label="Código" class="cell-strong">{{ $cita->codigo }}</td>
                                    <td data-label="Fecha">{{ Carbon::parse($cita->fecha_cita)->format('d/m/Y') }}</td>
                                    <td data-label="Hora">{{ Carbon::parse($cita->hora_inicio)->format('H:i') }}</td>
                                    <td data-label="Médico">{{ trim($cita->medico_nombre . ' ' . $cita->medico_apellido) ?: '—' }}</td>
                                    <td data-label="Especialidad">{{ $cita->especialidad ?? '—' }}</td>
                                    <td data-label="Estado"><x-admin.status-badge :status="$cita->estado" /></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-admin.card>

        @if ($bloqueos->isNotEmpty())
            <x-admin.card title="Historial de bloqueos" :padding="false">
                <div class="table-scroll">
                    <table class="data-table responsive-table">
                        <caption class="sr-only">Historial de bloqueos del paciente</caption>
                        <thead>
                            <tr>
                                <th scope="col">Bloqueado el</th>
                                <th scope="col">Desbloqueado el</th>
                                <th scope="col">Inasistencias</th>
                                <th scope="col">Motivo</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($bloqueos as $bloqueo)
                                <tr>
                                    <td data-label="Bloqueado el">{{ Carbon::parse($bloqueo->fecha_bloqueo)->format('d/m/Y') }}</td>
                                    <td data-label="Desbloqueado el">{{ $bloqueo->fecha_desbloqueo ? Carbon::parse($bloqueo->fecha_desbloqueo)->format('d/m/Y') : 'Vigente' }}</td>
                                    <td data-label="Inasistencias">{{ $bloqueo->inasistencias_acumuladas ?? '—' }}</td>
                                    <td data-label="Motivo" class="cell-wrap">{{ $bloqueo->motivo ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-admin.card>
        @endif
    </div>
</x-app-layout>
