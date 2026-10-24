@props(['messages'])

@if ($messages)
    <ul {{ $attributes->merge(['class' => 'form-errors']) }}>
        @foreach ((array) $messages as $message)
            <li>{{ $message }}</li>
        @endforeach
    </ul>
@endif
