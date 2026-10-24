@props(['title' => null, 'subtitle' => null, 'padding' => true])

<section {{ $attributes->merge(['class' => 'card']) }}>
    @if ($title || isset($action))
        <div class="card__header">
            <div>
                <h2 class="card__title">{{ $title }}</h2>
                @if ($subtitle)
                    <p class="card__subtitle">{{ $subtitle }}</p>
                @endif
            </div>

            @isset($action)
                <div>{{ $action }}</div>
            @endisset
        </div>
    @endif

    <div @class(['card__body' => $padding])>
        {{ $slot }}
    </div>
</section>
