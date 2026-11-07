<x-app-layout>
    <x-slot name="header">
        <div class="page-heading">
            <div>
                <a href="{{ route('roles.index') }}" class="back-link">← Roles y permisos</a>
                <h1 class="page-title">Crear rol</h1>
            </div>
        </div>
    </x-slot>

    <div class="page">
        <x-admin.card>
            <form method="POST" action="{{ route('roles.store') }}" class="patient-form">
                @csrf

                @include('roles.partials.form')

                <div class="form-actions form-actions--end">
                    <a href="{{ route('roles.index') }}" class="btn btn--secondary">Cancelar</a>
                    <x-primary-button>Crear rol</x-primary-button>
                </div>
            </form>
        </x-admin.card>
    </div>
</x-app-layout>
