<x-app-layout>
    <x-slot name="header">
        <div class="page-heading">
            <div>
                <a href="{{ route('usuarios.index') }}" class="back-link">← Usuarios</a>
                <h1 class="page-title">Editar cuenta</h1>
                <p class="page-subtitle">{{ $usuario->nombre }} {{ $usuario->apellido }} · {{ $usuario->etiquetaPerfil() }}</p>
            </div>
        </div>
    </x-slot>

    <div class="page">
        <x-admin.card>
            <form method="POST" action="{{ route('usuarios.update', $usuario) }}" class="patient-form">
                @csrf
                @method('PUT')

                @include('usuarios.partials.form')

                <div class="form-actions form-actions--end">
                    <a href="{{ route('usuarios.index') }}" class="btn btn--secondary">Cancelar</a>
                    <x-primary-button>Guardar cambios</x-primary-button>
                </div>
            </form>
        </x-admin.card>
    </div>
</x-app-layout>
