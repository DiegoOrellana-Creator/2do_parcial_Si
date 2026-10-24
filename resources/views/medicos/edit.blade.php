<x-app-layout>
    <x-slot name="header">
        <div class="page-heading">
            <div>
                <a href="{{ route('medicos.show', $medico) }}" class="back-link">← {{ $medico->usuario->nombre }} {{ $medico->usuario->apellido }}</a>
                <h1 class="page-title">Editar médico</h1>
            </div>
        </div>
    </x-slot>

    <div class="page">
        <x-admin.card>
            <form method="POST" action="{{ route('medicos.update', $medico) }}" class="patient-form">
                @csrf
                @method('PUT')

                @include('medicos.partials.form')

                <div class="form-actions form-actions--end">
                    <a href="{{ route('medicos.show', $medico) }}" class="btn btn--secondary">Cancelar</a>
                    <x-primary-button>Guardar cambios</x-primary-button>
                </div>
            </form>
        </x-admin.card>
    </div>
</x-app-layout>
