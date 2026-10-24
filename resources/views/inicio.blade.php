@php
use App\Models\User;

// Página de inicio de médicos, pacientes y cuentas sin perfil: solo muestra datos propios.
$usuario = Auth::user();
$perfil = $usuario->perfil();
$medico = $perfil === User::PERFIL_MEDICO ? \App\Models\Medico::with('especialidades')->find($usuario->id) : null;
$paciente = $perfil === User::PERFIL_PACIENTE ? \App\Models\Paciente::find($usuario->id) : null;

$pendientes = match ($perfil) {
    User::PERFIL_MEDICO => ['Mis citas'],
    default => [],
};

// Accesos del paciente y su próxima cita pendiente.
$proximaCita = $paciente
    ? \App\Models\Cita::where('id_paciente', $paciente->id_paciente)
        ->whereIn('estado', \App\Models\Cita::ESTADOS_CANCELABLES)
        ->where('fecha_cita', '>=', now()->toDateString())
        ->with(['medico.usuario', 'especialidad'])
        ->orderBy('fecha_cita')->orderBy('hora_inicio')
        ->first()
    : null;
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="greeting">
            <div>
                <p class="greeting__date">{{ Str::ucfirst(now()->locale('es')->isoFormat('dddd, D [de] MMMM [de] YYYY')) }}</p>
                <h1 class="greeting__title">Hola, {{ $usuario->nombre }}</h1>
            </div>
        </div>
    </x-slot>

    <div class="page">
        <div class="dashboard-grid dashboard-grid--halves">
            <x-admin.card title="Mi cuenta">
                <dl class="detail-list">
                    <div><dt>Nombre</dt><dd>{{ $usuario->nombre }} {{ $usuario->apellido }}</dd></div>
                    <div><dt>Correo electrónico</dt><dd>{{ $usuario->email }}</dd></div>
                    <div><dt>Nombre de usuario</dt><dd>{{ $usuario->username }}</dd></div>

                    @if ($medico)
                        <div><dt>Perfil</dt><dd>Médico</dd></div>
                        <div><dt>Matrícula</dt><dd>{{ $medico->matricula ?: 'No registrada' }}</dd></div>
                        <div>
                            <dt>Especialidades</dt>
                            <dd>{{ $medico->especialidades->isNotEmpty() ? $medico->especialidades->pluck('nombre')->join(', ') : 'Sin especialidades asignadas' }}</dd>
                        </div>
                    @elseif ($paciente)
                        <div><dt>Perfil</dt><dd>Paciente</dd></div>
                        <div><dt>Número de ficha</dt><dd>{{ $paciente->nro_ficha ?: 'Sin asignar' }}</dd></div>
                    @else
                        <div><dt>Perfil</dt><dd>Sin perfil asignado</dd></div>
                    @endif
                </dl>
            </x-admin.card>

            <x-admin.card title="Funciones disponibles">
                @if ($paciente)
                    @if ($proximaCita)
                        <p class="muted">
                            Tu próxima cita: {{ \Illuminate\Support\Carbon::parse($proximaCita->fecha_cita)->format('d/m/Y') }} a las {{ substr($proximaCita->hora_inicio, 0, 5) }}
                            con {{ $proximaCita->medico->usuario->nombre }} {{ $proximaCita->medico->usuario->apellido }} ({{ $proximaCita->especialidad->nombre }}).
                            <a href="{{ route('mis-citas.show', $proximaCita) }}">Ver detalle</a>
                        </p>
                    @else
                        <p class="muted">No tienes citas pendientes.</p>
                    @endif
                    <div class="quick-actions">
                        <a href="{{ route('mis-citas.create') }}" class="quick-action"><x-admin.icon name="plus" class="icon--lg" /> Solicitar cita</a>
                        <a href="{{ route('mis-citas.disponibilidad') }}" class="quick-action"><x-admin.icon name="magnifying-glass" class="icon--lg" /> Disponibilidad médica</a>
                        <a href="{{ route('mis-citas.index') }}" class="quick-action"><x-admin.icon name="clipboard" class="icon--lg" /> Mis citas</a>
                    </div>
                @elseif ($pendientes)
                    <p class="muted">Estas funciones de tu menú todavía no están habilitadas:</p>
                    <ul class="delete-summary">
                        @foreach ($pendientes as $funcion)
                            <li>{{ $funcion }}</li>
                        @endforeach
                    </ul>
                @else
                    <p class="muted">Tu cuenta todavía no tiene un perfil asignado. Contacta con la clínica si necesitas acceso.</p>
                @endif
                <p class="muted">Desde «Mi perfil» puedes revisar tus datos y cambiar tu contraseña.</p>
            </x-admin.card>
        </div>
    </div>
</x-app-layout>
