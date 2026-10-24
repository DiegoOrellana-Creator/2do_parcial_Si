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

                @can('pacientes.eliminar')
                    @if (empty($eliminacion['impedimentos']) && (int) $paciente->id_paciente !== (int) auth()->id())
                        @php
                            $conservaCuenta = ! empty($eliminacion['conservarCuenta']);
                            $totalContactos = $paciente->contactosEmergencia->count();
                        @endphp

                        {{-- Confirmación sin JavaScript: el botón solo abre la ventana; el borrado exige pulsar «Eliminar definitivamente» --}}
                        <x-modal name="confirmar-eliminacion" maxWidth="md" trigger-class="btn btn--danger">
                            <x-slot name="trigger">Eliminar</x-slot>

                            <form method="POST" action="{{ route('pacientes.destroy', $paciente) }}" class="modal__body">
                                @csrf
                                @method('DELETE')

                                <h2 class="section-title">¿Eliminar a {{ $usuario->nombre }} {{ $usuario->apellido }}?</h2>

                                <p class="section-text">Se eliminará definitivamente:</p>
                                <ul class="delete-summary">
                                    <li>La ficha de paciente{{ $paciente->nro_ficha ? ' (n.º ' . $paciente->nro_ficha . ')' : '' }}.</li>
                                    @if ($info)
                                        <li>Su información médica.</li>
                                    @endif
                                    @if ($totalContactos)
                                        <li>{{ $totalContactos }} {{ $totalContactos === 1 ? 'contacto de emergencia' : 'contactos de emergencia' }}.</li>
                                    @endif
                                    @unless ($conservaCuenta)
                                        <li>Su cuenta de acceso ({{ $usuario->username }}) con sus datos personales.</li>
                                        @if ($eliminacion['notificaciones'])
                                            <li>{{ $eliminacion['notificaciones'] }} {{ $eliminacion['notificaciones'] === 1 ? 'notificación' : 'notificaciones' }}.</li>
                                        @endif
                                        @if ($eliminacion['preferencias'])
                                            <li>Sus preferencias de contacto.</li>
                                        @endif
                                    @endunless
                                </ul>

                                @if ($conservaCuenta)
                                    <p class="section-text">
                                        Su cuenta de acceso ({{ $usuario->username }}) y sus datos personales se conservarán porque {{ implode(' y ', $eliminacion['conservarCuenta']) }}.
                                    </p>
                                @endif

                                <p class="section-text">No tiene citas ni bloqueos registrados. Esta acción no se puede deshacer.</p>

                                <div class="modal__actions">
                                    <a href="{{ route('pacientes.show', $paciente) }}" class="btn btn--secondary">Cancelar</a>
                                    <x-danger-button>Eliminar definitivamente</x-danger-button>
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

        @can('pacientes.eliminar')
            @if (! empty($eliminacion['impedimentos']))
                <div class="notice notice--info">
                    Este paciente no se puede eliminar porque {{ implode(' y ', $eliminacion['impedimentos']) }}.
                </div>
            @endif
        @endcan

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
