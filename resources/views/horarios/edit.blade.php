<x-app-layout>
    <x-slot name="header">
        <div class="page-heading">
            <div>
                <a href="{{ route('horarios.index', ['medico' => $horario->id_medico]) }}" class="back-link">← Horarios de atención</a>
                <h1 class="page-title">Editar horario</h1>
                <p class="page-subtitle">
                    {{ $horario->medico->usuario->nombre }} {{ $horario->medico->usuario->apellido }} ·
                    {{ $horario->nombreDia() }} {{ \App\Models\HorarioAtencion::hhmm($horario->hora_inicio) }}–{{ \App\Models\HorarioAtencion::hhmm($horario->hora_final) }}
                </p>
            </div>

            @can('horarios.eliminar')
                @if ($pendientes === 0)
                    <div class="page-heading__actions">
                        {{-- Confirmación sin JavaScript: el botón solo abre la ventana --}}
                        <x-modal name="confirmar-eliminacion" maxWidth="md" trigger-class="btn btn--danger">
                            <x-slot name="trigger">Eliminar</x-slot>

                            <form method="POST" action="{{ route('horarios.destroy', $horario) }}" class="modal__body">
                                @csrf
                                @method('DELETE')

                                <h2 class="section-title">¿Eliminar este horario?</h2>
                                <p class="section-text">
                                    Se eliminará el bloque de los {{ mb_strtolower($horario->nombreDia()) }} de
                                    {{ \App\Models\HorarioAtencion::hhmm($horario->hora_inicio) }} a {{ \App\Models\HorarioAtencion::hhmm($horario->hora_final) }}.
                                    No tiene citas pendientes. Las citas pasadas se conservan.
                                </p>

                                <div class="modal__actions">
                                    <a href="{{ route('horarios.edit', $horario) }}" class="btn btn--secondary">Cancelar</a>
                                    <x-danger-button>Eliminar definitivamente</x-danger-button>
                                </div>
                            </form>
                        </x-modal>
                    </div>
                @endif
            @endcan
        </div>
    </x-slot>

    <div class="page">
        @if ($pendientes > 0)
            <div class="notice notice--info">
                Este horario tiene {{ $pendientes }} {{ $pendientes === 1 ? 'cita pendiente' : 'citas pendientes' }}: no se puede modificar ni eliminar hasta cancelarlas.
            </div>
        @endif

        <x-admin.card>
            <form method="POST" action="{{ route('horarios.update', $horario) }}" class="patient-form">
                @csrf
                @method('PUT')

                @include('horarios.partials.form')

                <div class="form-actions form-actions--end">
                    <a href="{{ route('horarios.index', ['medico' => $horario->id_medico]) }}" class="btn btn--secondary">Cancelar</a>
                    <x-primary-button>Guardar cambios</x-primary-button>
                </div>
            </form>
        </x-admin.card>
    </div>
</x-app-layout>
