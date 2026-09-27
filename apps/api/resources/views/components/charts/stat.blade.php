{{--
    One figure, what it was last time, and which way it is heading.

    label:      what it is, in sentence case
    value:      already formatted — exact, never rounded to look tidy
    change:     relative change against the previous period (0.12 = +12%), or null
    upIsGood:   true when a rise is good news, false when it is bad (refunds,
                disputes), null when it is neither
    comparison: what the change is against, "vs previous 30 days"
    trend:      optional values for a sparkline
    hint:       a line of context under the figure
    href:       where the tile leads, if anywhere

    The direction is carried by an arrow and a word as well as a colour, so a
    change never depends on telling red from green.
--}}
@props([
    'label',
    'value',
    'change' => null,
    'upIsGood' => true,
    'comparison' => null,
    'trend' => null,
    'hue' => 1,
    'hint' => null,
    'href' => null,
])

@php
    $F = \App\Services\Analytics\Charts\Format::class;

    $direction = $change === null ? null : ($change > 0.0005 ? 'up' : ($change < -0.0005 ? 'down' : 'flat'));
    $tone = match (true) {
        $direction === null, $direction === 'flat', $upIsGood === null => 'flat',
        ($direction === 'up') === $upIsGood => 'good',
        default => 'bad',
    };
    $tag = filled($href) ? 'a' : 'div';
@endphp

<{{ $tag }} {{ $attributes->class(['mf-stat']) }} @if ($href) href="{{ $href }}" @endif data-stat="{{ \Illuminate\Support\Str::slug($label) }}">
    <span class="mf-stat-label">{{ $label }}</span>
    <span class="mf-stat-value">{{ $value }}</span>
    <span class="mf-stat-foot">
        <span>
            @if ($direction !== null)
                <span class="mf-delta mf-delta-{{ $tone }}">
                    <span aria-hidden="true">{{ match ($direction) { 'up' => '▲', 'down' => '▼', default => '▬' } }}</span>
                    {{ $F::delta($change) }}
                    <span class="mf-sr">{{ match ($direction) { 'up' => 'up', 'down' => 'down', default => 'unchanged' } }}{{ $tone === 'good' ? ', an improvement' : ($tone === 'bad' ? ', a worsening' : '') }}</span>
                </span>
                {{ $comparison }}
            @elseif (filled($hint))
                {{ $hint }}
            @elseif (filled($comparison))
                No change to compare
            @endif
        </span>
        @if (is_array($trend) && count($trend) >= 2)
            <x-charts.sparkline :values="$trend" :label="$label" :hue="$hue" />
        @endif
    </span>
    @if ($direction !== null && filled($hint))
        <span class="mf-note" style="margin-top: 0">{{ $hint }}</span>
    @endif
</{{ $tag }}>
