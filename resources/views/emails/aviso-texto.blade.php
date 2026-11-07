{{ $asunto }} · Clínica Santa Cruz

{{ $saludo }}

@foreach ($lineas as $linea)
{{ $linea }}

@endforeach
@foreach ($datos as $etiqueta => $valor)
{{ $etiqueta }}: {{ $valor }}
@endforeach
@if ($enlace)

{{ $textoEnlace ?? 'Ver en la clínica' }}: {{ $enlace }}
@endif

Este es un mensaje automático de la Clínica Santa Cruz. Por favor, no respondas a este correo.
