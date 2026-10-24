@props([
    'name',
    'show' => false,
    'maxWidth' => '2xl',
    'triggerClass' => 'btn btn--secondary',
])

{{--
    Ventana de confirmación sin JavaScript, basada en <details>/<summary>.
    - El slot «trigger» es el botón que la abre.
    - Se abre sola si $show es verdadero (por ejemplo, cuando hay errores de validación).
    - Para cerrarla, el contenido debe ofrecer un enlace que recargue la página.
--}}
<details id="{{ $name }}" {{ $attributes->except('focusable')->merge(['class' => 'modal modal--' . $maxWidth]) }} @if ($show) open @endif>
    <summary class="{{ $triggerClass }}">{{ $trigger }}</summary>

    <div class="modal__backdrop" aria-hidden="true"></div>

    <div class="modal__panel">
        {{ $slot }}
    </div>
</details>
