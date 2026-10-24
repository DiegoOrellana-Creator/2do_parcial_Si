<x-app-layout>
    <x-slot name="header">
        <div class="page-heading">
            <div>
                <a href="{{ route('pacientes.show', $paciente) }}" class="back-link">← {{ $paciente->usuario->nombre }} {{ $paciente->usuario->apellido }}</a>
                <h1 class="page-title">Editar paciente</h1>
            </div>
        </div>
    </x-slot>

    <div class="page">
        <x-admin.card>
            <form method="POST" action="{{ route('pacientes.update', $paciente) }}" class="patient-form">
                @csrf
                @method('PUT')

                @include('pacientes.partials.form')

                <div class="form-actions form-actions--end">
                    <a href="{{ route('pacientes.show', $paciente) }}" class="btn btn--secondary">Cancelar</a>
                    <x-primary-button>Guardar cambios</x-primary-button>
                </div>
            </form>
        </x-admin.card>
    </div>
</x-app-layout>
