<x-app-layout>
    <x-slot name="header">
        <div class="page-heading">
            <div>
                <a href="{{ route('especialidades.show', $especialidad) }}" class="back-link">← {{ $especialidad->nombre }}</a>
                <h1 class="page-title">Editar especialidad</h1>
            </div>
        </div>
    </x-slot>

    <div class="page">
        <x-admin.card>
            <form method="POST" action="{{ route('especialidades.update', $especialidad) }}" class="patient-form">
                @csrf
                @method('PUT')

                @include('especialidades.partials.form')

                <div class="form-actions form-actions--end">
                    <a href="{{ route('especialidades.show', $especialidad) }}" class="btn btn--secondary">Cancelar</a>
                    <x-primary-button>Guardar cambios</x-primary-button>
                </div>
            </form>
        </x-admin.card>
    </div>
</x-app-layout>
