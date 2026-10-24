@props(['status'])

@php
// Estados posibles de una cita (columna citas.estado) y su modificador CSS
$modifiers = [
    'Programada' => 'programada',
    'Confirmada' => 'confirmada',
    'Atendida' => 'atendida',
    'Cancelada' => 'cancelada',
    'No asistió' => 'no-asistio',
];
@endphp

<span {{ $attributes->merge(['class' => 'badge badge--' . ($modifiers[$status] ?? 'atendida')]) }}>
    {{ $status }}
</span>
