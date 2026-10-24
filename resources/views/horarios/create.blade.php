<x-app-layout>
    <x-slot name="header">
        <div class="page-heading">
            <div>
                <a href="{{ route('horarios.index') }}" class="back-link">← Horarios de atención</a>
                <h1 class="page-title">Registrar horario</h1>
            </div>
        </div>
    </x-slot>

    <div class="page">
        <x-admin.card>
            <form method="POST" action="{{ route('horarios.store') }}" class="patient-form">
                @csrf

                @include('horarios.partials.form')

                <div class="form-actions form-actions--end">
                    <a href="{{ route('horarios.index') }}" class="btn btn--secondary">Cancelar</a>
                    <x-primary-button>Registrar horario</x-primary-button>
                </div>
            </form>
        </x-admin.card>
    </div>
</x-app-layout>
