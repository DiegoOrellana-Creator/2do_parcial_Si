@php
use Illuminate\Support\Carbon;
@endphp

{{-- Datos del horario reservado. Requiere $cita (reserva) y, opcionalmente, $mostrarPaciente. --}}
<dl class="detail-list">
    @if ($mostrarPaciente ?? false)
        <div><dt>Paciente</dt><dd>{{ $cita->paciente->usuario->nombre }} {{ $cita->paciente->usuario->apellido }}{{ $cita->paciente->nro_ficha ? ' (' . $cita->paciente->nro_ficha . ')' : '' }}</dd></div>
    @endif
    <div><dt>Especialidad</dt><dd>{{ $cita->especialidad->nombre }}</dd></div>
    <div><dt>Médico</dt><dd>{{ $cita->medico->usuario->nombre }} {{ $cita->medico->usuario->apellido }}</dd></div>
    <div><dt>Fecha</dt><dd>{{ Str::ucfirst(Carbon::parse($cita->fecha_cita)->locale('es')->isoFormat('dddd, D [de] MMMM [de] YYYY')) }}</dd></div>
    <div><dt>Hora</dt><dd>{{ substr($cita->hora_inicio, 0, 5) }}–{{ substr($cita->hora_fin, 0, 5) }}</dd></div>
    @if (filled($cita->motivo))
        <div><dt>Motivo</dt><dd class="pre-line">{{ $cita->motivo }}</dd></div>
    @endif
    <div><dt>Importe</dt><dd><strong>Bs {{ number_format((float) $cita->precioCobrable(), 2, ',', '.') }}</strong></dd></div>
</dl>
