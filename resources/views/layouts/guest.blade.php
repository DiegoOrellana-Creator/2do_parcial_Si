<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>Clínica Santa Cruz</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Estilos -->
        <link rel="stylesheet" href="{{ asset('css/clinica.css') }}">
    </head>
    <body>
        <div class="auth">
            <!-- Imagen: fondo completo en móvil, mitad izquierda en PC -->
            <div class="auth__image" style="background-image: url('{{ asset('images/login.jpg') }}');"></div>

            <!-- Contenedor del Formulario -->
            <div class="auth__panel">
                <!-- Tarjeta blanca del formulario -->
                <div class="auth__card">
                    <!-- Identidad de la clínica -->
                    <div class="auth__logo">
                        <x-application-logo />
                    </div>

                    {{ $slot }}
                </div>
            </div>
        </div>
    </body>
</html>
