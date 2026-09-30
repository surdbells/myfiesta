{{--
    A ranking: one horizontal bar per item, largest first as given.

    items: list<array{
        label: string,
        value: int|float,
        hint?: string,        shown in the tooltip and the table
        url?: string,         the row links there
        capacity?: int|null,  draws a track the bar fills, "45 / 100"
        other?: bool,         a folded remainder, drawn in gray
    }>
    format: money (minor units, with currency) | number | percent

    Values sit at the tip of each bar. Labels too long for the column are cut
    with an ellipsis and given in full in the row's tooltip and the table.

    Drawn in HTML rather than SVG, so its words are always the page's size.
    As a picture it was scaled to its box: 12px labels read at 8px on a phone,
    and a minimum width that kept them legible pushed every value past the
    right edge, reached only by scrolling sideways — and a ranking is read
    from its values. Where the chart is narrow, each label sits on its own
    line above its bar instead of beside it.
--}}
@props([
    'title',
    'description' => null,
    'items' => [],
    'format' => 'number',
    'currency' => null,
    'hue' => 1,
    'empty' => 'Nothing to rank for this period.',
    'labelHeading' => 'Item',
    'valueHeading' => 'Value',
])

@php
    $F = \App\Services\Analytics\Charts\Format::class;

    $items = array_values($items);

    $max = 0.0;
    $hasData = false;
    foreach ($items as $item) {
        $max = max($max, (float) $item['value'], (float) ($item['capacity'] ?? 0));
        $hasData = $hasData || (float) $item['value'] > 0;
    }

    // A share of the longest bar there is room for, 0 to 1.
    $share = fn ($v) => $max > 0 ? max(0, (float) $v) / $max : 0.0;

    $display = function (array $item) use ($F, $format, $currency): string {
        $value = $F::value($item['value'], $format, $currency);

        if (array_key_exists('capacity', $item) && $item['capacity'] !== null) {
            $share = $item['capacity'] > 0 ? ' ('.$F::percent($item['value'] / $item['capacity'], 0).')' : '';

            return $value.' / '.$F::number($item['capacity']).$share;
        }

        return $value;
    };

    $number = fn (float $v) => rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.') ?: '0';
@endphp

<figure {{ $attributes->class(['mf-chart']) }} data-chart="hbar">
    @if (! $hasData)
        <div class="mf-empty" role="note">{{ $empty }}</div>
    @else
        <ol class="mf-hbar" aria-label="{{ $title }}">
            @foreach ($items as $item)
                @php
                    $length = $share($item['value']);
                    $capacity = array_key_exists('capacity', $item) && $item['capacity'] !== null && $item['capacity'] > 0 ? $share($item['capacity']) : 0.0;
                    // What the bar and its track reach together; the value sits past it.
                    $reach = max($length, $capacity);
                    $tip = $item['label'].': '.$display($item).(filled($item['hint'] ?? null) ? ' — '.$item['hint'] : '');
                    $rowSlot = ($item['other'] ?? false) ? 0 : (int) ($item['slot'] ?? $hue);
                    $tag = filled($item['url'] ?? null) ? 'a' : 'div';
                @endphp
                <li>
                    <{{ $tag }} class="mf-row mf-s{{ $rowSlot }}" @if ($tag === 'a') href="{{ $item['url'] }}" @endif title="{{ $tip }}">
                        <span class="mf-hbar-label">{{ $item['label'] }}</span>
                        <span class="mf-hbar-line">
                            <span class="mf-hbar-bars" style="--mf-reach: {{ $number($reach) }}">
                                @if ($capacity > 0)
                                    <span class="mf-track" style="width: {{ $number($capacity / $reach * 100) }}%"></span>
                                @endif
                                @if ($length > 0)
                                    <span class="mf-bar" style="width: {{ $number($length / $reach * 100) }}%"></span>
                                @endif
                            </span>
                            <span class="mf-value">{{ $display($item) }}</span>
                        </span>
                    </{{ $tag }}>
                </li>
            @endforeach
        </ol>

        <x-charts.data-table
            :caption="$title"
            :headers="[$labelHeading, $valueHeading]"
            :rows="array_map(fn ($item) => [$item['label'].(filled($item['hint'] ?? null) ? ' ('.$item['hint'].')' : ''), $display($item)], $items)"
        />
    @endif
</figure>
