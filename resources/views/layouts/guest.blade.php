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

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans text-gray-900 antialiased">
        <!-- Contenedor principal relativo -->
        <div class="relative flex min-h-screen w-full bg-white md:flex-row">
            
            <!-- Imagen: Fondo completo en móvil, mitad izquierda en PC -->
            <div class="absolute inset-0 z-0 bg-cover bg-center md:relative md:w-1/2" style="background-image: url('{{ asset('images/login.jpg') }}');">
                <!-- Capa oscura semitransparente solo en móvil para que el formulario resalte -->
                <div class="absolute inset-0 bg-black/30 md:hidden"></div>
            </div>

            <!-- Contenedor del Formulario -->
            <div class="relative z-10 flex w-full flex-col items-center justify-center p-6 md:w-1/2 md:p-12">
                <!-- Tarjeta blanca del formulario -->
                <div class="w-full max-w-sm rounded-2xl bg-white p-8 shadow-xl md:rounded-none md:bg-transparent md:p-0 md:shadow-none">
                    <!-- Identidad de la clínica -->
                    <div class="mb-8 flex justify-center">
                        <x-application-logo class="h-16 w-auto" />
                    </div>

                    {{ $slot }}
                </div>
            </div>
            
        </div>
    </body>
</html>