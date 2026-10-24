<x-app-layout>
    <x-slot name="header">
        <div class="page-heading">
            <div>
                <a href="{{ route('medicos.index') }}" class="back-link">← Médicos</a>
                <h1 class="page-title">Registrar médico</h1>
            </div>
        </div>
    </x-slot>

    <div class="page">
        <x-admin.card>
            <form method="POST" action="{{ route('medicos.store') }}" class="patient-form">
                @csrf

                @include('medicos.partials.form')

                <div class="form-actions form-actions--end">
                    <a href="{{ route('medicos.index') }}" class="btn btn--secondary">Cancelar</a>
                    <x-primary-button>Registrar médico</x-primary-button>
                </div>
            </form>
        </x-admin.card>
    </div>
</x-app-layout>
