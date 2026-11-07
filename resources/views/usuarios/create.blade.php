<x-app-layout>
    <x-slot name="header">
        <div class="page-heading">
            <div>
                <a href="{{ route('usuarios.index') }}" class="back-link">← Usuarios</a>
                <h1 class="page-title">Crear cuenta de personal</h1>
                <p class="page-subtitle">Pacientes y médicos se registran en sus propios módulos</p>
            </div>
        </div>
    </x-slot>

    <div class="page">
        <x-admin.card>
            <form method="POST" action="{{ route('usuarios.store') }}" class="patient-form">
                @csrf

                @include('usuarios.partials.form')

                <div class="form-actions form-actions--end">
                    <a href="{{ route('usuarios.index') }}" class="btn btn--secondary">Cancelar</a>
                    <x-primary-button>Crear cuenta</x-primary-button>
                </div>
            </form>
        </x-admin.card>
    </div>
</x-app-layout>
