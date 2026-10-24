@props(['align' => 'right', 'width' => '48', 'contentClasses' => ''])

@php
// Menú desplegable con <details>/<summary>: se abre y se cierra sin JavaScript.
// La prop «width» se conserva por compatibilidad; el ancho lo define clinica.css.
$alignmentClasses = $align === 'left' ? 'dropdown__panel--left' : 'dropdown__panel--right';
@endphp

<details {{ $attributes->merge(['class' => 'dropdown']) }}>
    <summary class="dropdown__trigger">
        {{ $trigger }}
    </summary>

    <div class="dropdown__panel {{ $alignmentClasses }} {{ $contentClasses }}">
        {{ $content }}
    </div>
</details>
