{{-- Estado de la especialidad (especialidad.estado) --}}
<span @class([
    'badge',
    'badge--confirmada' => $especialidad->estado === \App\Models\Especialidad::ACTIVA,
    'badge--atendida' => $especialidad->estado !== \App\Models\Especialidad::ACTIVA,
])>{{ $especialidad->estado === \App\Models\Especialidad::ACTIVA ? 'Activa' : 'Inactiva' }}</span>
