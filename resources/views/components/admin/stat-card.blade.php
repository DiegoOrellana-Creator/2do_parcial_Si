@props(['label', 'value', 'icon', 'trend' => null, 'trendTone' => 'neutral'])

<div {{ $attributes->merge(['class' => 'stat-card']) }}>
    <div class="stat-card__top">
        <div>
            <p class="stat-card__label">{{ $label }}</p>
            <p class="stat-card__value">{{ $value }}</p>
        </div>

        <div class="stat-card__icon">
            <x-admin.icon :name="$icon" class="icon--lg" />
        </div>
    </div>

    @if ($trend)
        <p @class([
            'stat-card__trend',
            'stat-card__trend--positive' => $trendTone === 'positive',
            'stat-card__trend--negative' => $trendTone === 'negative',
        ])>{{ $trend }}</p>
    @endif
</div>
