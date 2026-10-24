{{-- Mensajes de resultado tras una redirección (session «status» y «error») --}}
@if (session('status'))
    <div class="notice notice--success" role="status">{{ session('status') }}</div>
@endif

@if (session('error'))
    <div class="notice notice--error" role="alert">{{ session('error') }}</div>
@endif
