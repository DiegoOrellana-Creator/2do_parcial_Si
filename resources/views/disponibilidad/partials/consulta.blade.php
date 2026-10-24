{{-- Formulario GET de consulta de disponibilidad (lo comparten Disponibilidad y Registrar cita) --}}
<form method="GET" action="{{ $accion }}" class="search-form" role="search">
    <div class="search-form__field">
        <label for="especialidad" class="form-label">Especialidad</label>
        <select id="especialidad" name="especialidad" required class="form-input" @if ($consulta['errores']->has('especialidad')) aria-invalid="true" @endif>
            <option value="">Selecciona una especialidad</option>
            @foreach ($especialidades as $opcion)
                <option value="{{ $opcion->id_especialidad }}" @selected($consulta['especialidad'] === (int) $opcion->id_especialidad)>{{ $opcion->nombre }}</option>
            @endforeach
        </select>
    </div>

    <div class="search-form__field">
        <label for="fecha" class="form-label">Fecha</label>
        <input id="fecha" name="fecha" type="date" required class="form-input"
               min="{{ now()->toDateString() }}" max="{{ now()->addDays(\App\Http\Controllers\DisponibilidadController::DIAS_MAXIMOS)->toDateString() }}"
               value="{{ $consulta['fecha']->toDateString() }}" @if ($consulta['errores']->has('fecha')) aria-invalid="true" @endif>
    </div>

    <div class="search-form__field">
        <label for="medico" class="form-label">Médico (opcional)</label>
        <select id="medico" name="medico" class="form-input">
            <option value="">Todos los de la especialidad</option>
            @foreach ($medicos as $opcion)
                <option value="{{ $opcion->id_medico }}" @selected($consulta['medico'] === (int) $opcion->id_medico)>{{ $opcion->usuario->apellido }}, {{ $opcion->usuario->nombre }}</option>
            @endforeach
        </select>
        @if ($medicos->isEmpty())
            <p class="field-hint">Elige una especialidad y pulsa «Consultar» para ver sus médicos.</p>
        @endif
    </div>

    @isset($paciente)
        @if ($paciente)
            <input type="hidden" name="paciente" value="{{ $paciente }}">
        @endif
    @endisset

    <div class="search-form__field search-form__field--boton">
        <button type="submit" class="btn btn--primary">Consultar</button>
    </div>
</form>

@if ($consulta['errores']->isNotEmpty())
    <div class="card__body">
        <div class="notice notice--error" role="alert">
            <ul>
                @foreach ($consulta['errores']->all() as $mensaje)
                    <li>{{ $mensaje }}</li>
                @endforeach
            </ul>
        </div>
    </div>
@endif
