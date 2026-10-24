<x-app-layout>
    <x-slot name="header">
        <div class="page-heading">
            <div>
                <a href="{{ route('especialidades.index') }}" class="back-link">← Especialidades</a>
                <h1 class="page-title">Registrar especialidad</h1>
            </div>
        </div>
    </x-slot>

    <div class="page">
        <x-admin.card>
            <form method="POST" action="{{ route('especialidades.store') }}" class="patient-form">
                @csrf

                @include('especialidades.partials.form')

                <div class="form-actions form-actions--end">
                    <a href="{{ route('especialidades.index') }}" class="btn btn--secondary">Cancelar</a>
                    <x-primary-button>Registrar especialidad</x-primary-button>
                </div>
            </form>
        </x-admin.card>
    </div>
</x-app-layout>
