<x-app-layout>
    <x-slot name="header">
        <div class="page-heading">
            <div>
                <a href="{{ route('especialidades.index') }}" class="back-link">← Especialidades</a>
                <h1 class="page-title">{{ $especialidad->nombre }}</h1>
                <div class="page-heading__meta">
                    @include('especialidades.partials.estado')
                </div>
            </div>

            <div class="page-heading__actions">
                @can('especialidades.editar')
                    <a href="{{ route('especialidades.edit', $especialidad) }}" class="btn btn--primary">Editar</a>
                @endcan

                @can('especialidades.eliminar')
                    @if (empty($impedimentos))
                        {{-- Confirmación sin JavaScript: el botón solo abre la ventana; el borrado exige pulsar «Eliminar definitivamente» --}}
                        <x-modal name="confirmar-eliminacion" maxWidth="md" trigger-class="btn btn--danger">
                            <x-slot name="trigger">Eliminar</x-slot>

                            <form method="POST" action="{{ route('especialidades.destroy', $especialidad) }}" class="modal__body">
                                @csrf
                                @method('DELETE')

                                <h2 class="section-title">¿Eliminar la especialidad «{{ $especialidad->nombre }}»?</h2>
                                <p class="section-text">
                                    No está asignada a ningún médico ni tiene citas registradas, así que solo se eliminará
                                    la especialidad. Esta acción no se puede deshacer.
                                </p>

                                <div class="modal__actions">
                                    <a href="{{ route('especialidades.show', $especialidad) }}" class="btn btn--secondary">Cancelar</a>
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

        @can('especialidades.eliminar')
            @if (! empty($impedimentos))
                <div class="notice notice--info">
                    Esta especialidad no se puede eliminar porque {{ implode(' y ', $impedimentos) }}:
                    eliminarla borraría también esa información.
                    @if ($especialidad->estado === \App\Models\Especialidad::ACTIVA)
                        Si ya no se ofrece, puedes <a href="{{ route('especialidades.edit', $especialidad) }}" class="link">desactivarla</a>.
                    @endif
                </div>
            @endif
        @endcan

        <div class="dashboard-grid dashboard-grid--halves">
            <x-admin.card title="Datos de la especialidad">
                <dl class="detail-list">
                    <div><dt>Nombre</dt><dd>{{ $especialidad->nombre }}</dd></div>
                    <div><dt>Estado</dt><dd>{{ $especialidad->estado === \App\Models\Especialidad::ACTIVA ? 'Activa' : 'Inactiva' }}</dd></div>
                    <div><dt>Descripción</dt><dd class="pre-line">{{ filled($especialidad->descripcion) ? $especialidad->descripcion : 'Sin descripción' }}</dd></div>
                    <div><dt>Médicos asignados</dt><dd>{{ $medicos->count() }}</dd></div>
                    <div><dt>Citas registradas</dt><dd>{{ $totalCitas }}</dd></div>
                </dl>
            </x-admin.card>

            <x-admin.card title="Médicos que la ejercen" :padding="false">
                @forelse ($medicos as $medico)
                    <div class="contact-item">
                        <p class="item-title">{{ $medico->nombre }} {{ $medico->apellido }}</p>
                        <p class="item-meta">
                            {{ $medico->matricula ? 'Matrícula ' . $medico->matricula : 'Sin matrícula registrada' }}
                            · {{ $medico->estado }}
                        </p>
                    </div>
                @empty
                    <p class="muted card__body">Ningún médico tiene asignada esta especialidad.</p>
                @endforelse
            </x-admin.card>
        </div>
    </div>
</x-app-layout>
