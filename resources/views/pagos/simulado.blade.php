<x-guest-layout>
    <h1 class="auth-title">Pasarela de prueba</h1>

    <div class="notice notice--info">Modo de prueba: esta página simula una pasarela de pago y no cobra dinero real.</div>

    <dl class="detail-list">
        <div><dt>Concepto</dt><dd>Consulta de {{ $pago->cita->especialidad->nombre }}</dd></div>
        <div><dt>Cita</dt><dd>{{ $pago->cita->codigo }}</dd></div>
        <div><dt>Importe</dt><dd>Bs {{ number_format((float) $pago->monto, 2, ',', '.') }}</dd></div>
    </dl>

    <form method="POST" action="{{ $accion }}" class="form-actions form-actions--end">
        @csrf
        <button type="submit" name="accion" value="cancelar" class="btn btn--secondary">Cancelar</button>
        <button type="submit" name="accion" value="pagar" class="btn btn--primary">Pagar Bs {{ number_format((float) $pago->monto, 2, ',', '.') }}</button>
    </form>
</x-guest-layout>
