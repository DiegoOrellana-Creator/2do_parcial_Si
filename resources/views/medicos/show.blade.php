@php
$usuario = $medico->usuario;
$nacimiento = $usuario->fecha_nacimiento ? \Illuminate\Support\Carbon::parse($usuario->fecha_nacimiento) : null;
$valor = fn ($dato) => filled($dato) ? $dato : null;
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="page-heading">
            <div>
                <a href="{{ route('medicos.index') }}" class="back-link">← Médicos</a>
                <h1 class="page-title">{{ $usuario->nombre }} {{ $usuario->apellido }}</h1>
                <div class="page-heading__meta">
                    @include('medicos.partials.estado')
                    @if ($medico->matricula)
                        <span class="page-subtitle">Matrícula {{ $medico->matricula }}</span>
                    @endif
                </div>
            </div>

            <div class="page-heading__actions">
                @can('medicos.editar')
                    <a href="{{ route('medicos.edit', $medico) }}" class="btn btn--primary">Editar</a>
                @endcan
            </div>
        </div>
    </x-slot>

    <div class="page">
        <x-admin.flash />

        <div class="dashboard-grid dashboard-grid--halves">
            <x-admin.card title="Datos personales">
                <dl class="detail-list">
                    <div><dt>Nombre completo</dt><dd>{{ $usuario->nombre }} {{ $usuario->apellido }}</dd></div>
                    <div><dt>Carnet de identidad</dt><dd>{{ $valor($usuario->ci) ?? 'No registrado' }}</dd></div>
                    <div>
                        <dt>Fecha de nacimiento</dt>
                        <dd>{{ $nacimiento ? $nacimiento->format('d/m/Y') . ' (' . $nacimiento->age . ' años)' : 'No registrada' }}</dd>
                    </div>
                    <div><dt>Sexo</dt><dd>{{ $valor($usuario->sexo) ?? 'Sin especificar' }}</dd></div>
                    <div><dt>Teléfono</dt><dd>{{ $valor($usuario->telefono) ?? 'No registrado' }}</dd></div>
                    <div><dt>Dirección</dt><dd>{{ $valor($usuario->direccion) ?? 'No registrada' }}</dd></div>
                </dl>
            </x-admin.card>

            <x-admin.card title="Datos profesionales y cuenta">
                <dl class="detail-list">
                    <div><dt>Matrícula</dt><dd>{{ $valor($medico->matricula) ?? 'No registrada' }}</dd></div>
                    <div><dt>Código interno</dt><dd>{{ $valor($medico->codigo) ?? 'No asignado' }}</dd></div>
                    <div><dt>Estado</dt><dd>{{ $usuario->estado }}</dd></div>
                    <div><dt>Correo electrónico</dt><dd>{{ $usuario->email }}</dd></div>
                    <div><dt>Nombre de usuario</dt><dd>{{ $usuario->username }}</dd></div>
                </dl>
            </x-admin.card>

            <x-admin.card title="Especialidades que ejerce" :padding="false">
                @forelse ($medico->especialidades as $especialidad)
                    <div class="contact-item">
                        <p class="item-title">{{ $especialidad->nombre }}</p>
                        <p class="item-meta">{{ $especialidad->estado === \App\Models\Especialidad::ACTIVA ? 'Activa' : 'Inactiva: no se ofrece en nuevas citas' }}</p>
                    </div>
                @empty
                    <p class="muted card__body">No tiene especialidades asignadas.</p>
                @endforelse
            </x-admin.card>

            <x-admin.card title="Actividad registrada">
                <dl class="detail-list">
                    <div><dt>Citas registradas</dt><dd>{{ $resumen['citas'] }}</dd></div>
                    <div><dt>Citas pendientes</dt><dd>{{ $resumen['citasPendientes'] }}</dd></div>
                    <div><dt>Horarios de atención</dt><dd>{{ $resumen['horarios'] }}</dd></div>
                    <div><dt>Bloqueos de agenda</dt><dd>{{ $resumen['bloqueos'] }}</dd></div>
                </dl>
            </x-admin.card>
        </div>
    </div>
</x-app-layout>
