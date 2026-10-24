{{-- Estado del médico: es el estado de su cuenta (users.estado) --}}
<span @class([
    'badge',
    'badge--confirmada' => $medico->usuario->estado === \App\Models\User::ESTADO_ACTIVO,
    'badge--atendida' => $medico->usuario->estado !== \App\Models\User::ESTADO_ACTIVO,
])>{{ $medico->usuario->estado }}</span>
