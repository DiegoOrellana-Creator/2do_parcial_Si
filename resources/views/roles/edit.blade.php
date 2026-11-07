<x-app-layout>
    <x-slot name="header">
        <div class="page-heading">
            <div>
                <a href="{{ route('roles.index') }}" class="back-link">← Roles y permisos</a>
                <h1 class="page-title">Rol {{ $rol->nombre }}</h1>
                @if ($rol->permisosEditables())
                    <p class="page-subtitle">Los cambios de permisos se aplican de inmediato a las cuentas con este rol</p>
                @endif
            </div>
        </div>
    </x-slot>

    <div class="page">
        <x-admin.card>
            <form method="POST" action="{{ route('roles.update', $rol) }}" class="patient-form">
                @csrf
                @method('PUT')

                @include('roles.partials.form')

                <div class="form-actions form-actions--end">
                    <a href="{{ route('roles.index') }}" class="btn btn--secondary">Cancelar</a>
                    <x-primary-button>Guardar cambios</x-primary-button>
                </div>
            </form>
        </x-admin.card>
    </div>
</x-app-layout>
