{{-- Estado de la cuenta (users.estado) y bloqueo del paciente (paciente.bloqueado) --}}
<span class="badge-group">
    <span @class([
        'badge',
        'badge--confirmada' => $paciente->usuario->estado === 'Activo',
        'badge--atendida' => $paciente->usuario->estado !== 'Activo',
    ])>{{ $paciente->usuario->estado }}</span>

    @if ($paciente->bloqueado)
        <span class="badge badge--cancelada">Bloqueado</span>
    @endif
</span>
