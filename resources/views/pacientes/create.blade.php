<x-app-layout>
    <x-slot name="header">
        <div class="page-heading">
            <div>
                <a href="{{ route('pacientes.index') }}" class="back-link">← Pacientes</a>
                <h1 class="page-title">Registrar paciente</h1>
            </div>
        </div>
    </x-slot>

    <div class="page">
        <x-admin.card>
            <form method="POST" action="{{ route('pacientes.store') }}" class="patient-form">
                @csrf

                @include('pacientes.partials.form')

                <div class="form-actions form-actions--end">
                    <a href="{{ route('pacientes.index') }}" class="btn btn--secondary">Cancelar</a>
                    <x-primary-button>Registrar paciente</x-primary-button>
                </div>
            </form>
        </x-admin.card>
    </div>
</x-app-layout>
