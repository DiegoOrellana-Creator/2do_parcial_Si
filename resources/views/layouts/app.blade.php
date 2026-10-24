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
    <body class="app-body">
        <div class="app">
            <!-- Menú lateral (escritorio) -->
            <aside class="sidebar">
                <div class="sidebar__brand">
                    <a href="{{ route('dashboard') }}">
                        <x-application-logo />
                    </a>
                </div>

                <div class="sidebar__nav">
                    @include('layouts.partials.sidebar', ['menuLabel' => 'Menú principal'])
                </div>

                <div class="sidebar__footer">
                    Clínica Santa Cruz · Sistema de citas médicas
                </div>
            </aside>

            <div class="app__main">
                @include('layouts.partials.topbar')

                <!-- Page Heading -->
                @isset($header)
                    <header class="page-header">
                        <div class="page-header__inner">
                            {{ $header }}
                        </div>
                    </header>
                @endisset

                <!-- Page Content -->
                <main class="app__content">
                    {{ $slot }}
                </main>
            </div>
        </div>
    </body>
</html>
