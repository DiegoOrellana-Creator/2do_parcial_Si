@php
use Illuminate\Support\Carbon;

$paciente = $cita->paciente->usuario;
$medico = $cita->medico->usuario;
$bs = fn ($monto) => 'Bs ' . number_format((float) $monto, 2, ',', '.');
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Comprobante N.º {{ $pago->id_pago }} · Clínica Santa Cruz</title>
    <link rel="stylesheet" href="{{ asset('css/clinica.css') }}">
</head>
<body class="comprobante-pagina">
    <main class="comprobante">
        <nav class="comprobante__acciones" aria-label="Acciones">
            <a href="{{ $volver }}" class="back-link">← Volver a la cita</a>
            <p class="field-hint">Para imprimir o guardar en PDF, usa Ctrl+P (en Mac, Cmd+P).</p>
        </nav>

        <header class="comprobante__encabezado">
            <x-application-logo />
            <div class="comprobante__titulo">
                <h1>Comprobante de pago</h1>
                <p>N.º {{ str_pad((string) $pago->id_pago, 6, '0', STR_PAD_LEFT) }}</p>
            </div>
        </header>

        <section class="comprobante__seccion" aria-labelledby="titulo-paciente">
            <h2 id="titulo-paciente">Paciente</h2>
            <dl class="detail-list">
                <div><dt>Nombre</dt><dd>{{ $paciente->nombre }} {{ $paciente->apellido }}</dd></div>
                @if ($paciente->ci)
                    <div><dt>CI</dt><dd>{{ $paciente->ci }}</dd></div>
                @endif
                @if ($cita->paciente->nro_ficha)
                    <div><dt>N.º de ficha</dt><dd>{{ $cita->paciente->nro_ficha }}</dd></div>
                @endif
            </dl>
        </section>

        <section class="comprobante__seccion" aria-labelledby="titulo-cita">
            <h2 id="titulo-cita">Cita</h2>
            <dl class="detail-list">
                <div><dt>Código</dt><dd>{{ $cita->codigo }}</dd></div>
                <div><dt>Especialidad</dt><dd>{{ $cita->especialidad->nombre }}</dd></div>
                <div><dt>Médico</dt><dd>{{ $medico->nombre }} {{ $medico->apellido }}</dd></div>
                <div><dt>Fecha y hora</dt><dd>{{ Carbon::parse($cita->fecha_cita)->format('d/m/Y') }} · {{ substr($cita->hora_inicio, 0, 5) }}–{{ substr($cita->hora_fin, 0, 5) }}</dd></div>
            </dl>
        </section>

        <section class="comprobante__seccion" aria-labelledby="titulo-pago">
            <h2 id="titulo-pago">Pago</h2>
            <dl class="detail-list">
                <div><dt>Concepto</dt><dd>Consulta de {{ $cita->especialidad->nombre }}</dd></div>
                <div><dt>Método</dt><dd>{{ $pago->metodo_de_pago ?? '—' }}</dd></div>
                @if ($pago->id_transaccion)
                    <div><dt>Referencia</dt><dd class="cell-ref">{{ $pago->id_transaccion }}</dd></div>
                @endif
                <div><dt>Fecha de pago</dt><dd>{{ $pago->fecha_pago?->format('d/m/Y H:i') ?? '—' }}</dd></div>
                <div><dt>Estado</dt><dd>{{ $pago->estado_pago }}</dd></div>
            </dl>
            <p class="comprobante__total"><span>Total pagado</span> <strong>{{ $bs($pago->monto) }}</strong></p>
        </section>

        <footer class="comprobante__pie">
            Clínica Santa Cruz · Comprobante emitido el {{ now()->format('d/m/Y H:i') }}. Este documento acredita el pago de la consulta indicada.
        </footer>
    </main>
</body>
</html>
