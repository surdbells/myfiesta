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
    $n = count($items);

    $W = 640;
    $rowH = 30;
    $pt = 4;
    $labelW = 200;
    $valueW = 120;
    $barX = $labelW + 12;
    $barMax = $W - $barX - $valueW;
    $H = $pt * 2 + $n * $rowH;
    $thick = 14;

    $max = 0.0;
    $hasData = false;
    foreach ($items as $item) {
        $max = max($max, (float) $item['value'], (float) ($item['capacity'] ?? 0));
        $hasData = $hasData || (float) $item['value'] > 0;
    }

    $c = fn (float $v) => $F::coord($v);
    $len = fn ($v) => $max > 0 ? max(0, (float) $v) / $max * $barMax : 0;

    // A bar square at its start and rounded at its data end.
    $shape = function (float $x, float $y, float $w, float $h) use ($c): string {
        $r = min(4, $w / 2, $h / 2);

        return 'M'.$c($x).' '.$c($y)
            .' L'.$c($x + $w - $r).' '.$c($y)
            .' Q'.$c($x + $w).' '.$c($y).' '.$c($x + $w).' '.$c($y + $r)
            .' L'.$c($x + $w).' '.$c($y + $h - $r)
            .' Q'.$c($x + $w).' '.$c($y + $h).' '.$c($x + $w - $r).' '.$c($y + $h)
            .' L'.$c($x).' '.$c($y + $h).' Z';
    };

    $display = function (array $item) use ($F, $format, $currency): string {
        $value = $F::value($item['value'], $format, $currency);

        if (array_key_exists('capacity', $item) && $item['capacity'] !== null) {
            $share = $item['capacity'] > 0 ? ' ('.$F::percent($item['value'] / $item['capacity'], 0).')' : '';

            return $value.' / '.$F::number($item['capacity']).$share;
        }

        return $value;
    };

    $uid = 'mfh-'.substr(md5($title.json_encode($items)), 0, 10);

    $summary = $description ?? ($n === 0 ? 'No items.' : collect($items)->take(3)
        ->map(fn ($item, $i) => ($i + 1).'. '.$item['label'].' '.$display($item))
        ->implode('; ').($n > 3 ? '; and '.($n - 3).' more.' : '.'));
@endphp

<figure {{ $attributes->class(['mf-chart']) }} data-chart="hbar">
    @if (! $hasData)
        <div class="mf-empty" role="note">{{ $empty }}</div>
    @else
        <div class="mf-scroll">
            <svg viewBox="0 0 {{ $W }} {{ $H }}" role="img" aria-labelledby="{{ $uid }}-t {{ $uid }}-d" preserveAspectRatio="xMidYMid meet" xmlns="http://www.w3.org/2000/svg">
                <title id="{{ $uid }}-t">{{ $title }}</title>
                <desc id="{{ $uid }}-d">{{ $summary }}</desc>

                <line class="mf-axis" x1="{{ $barX }}" x2="{{ $barX }}" y1="{{ $pt }}" y2="{{ $H - $pt }}" aria-hidden="true" />

                @foreach ($items as $i => $item)
                    @php
                        $y = $pt + $i * $rowH;
                        $mid = $y + $rowH / 2;
                        $length = $len($item['value']);
                        $tip = $item['label'].': '.$display($item).(filled($item['hint'] ?? null) ? ' — '.$item['hint'] : '');
                        $rowSlot = ($item['other'] ?? false) ? 0 : (int) ($item['slot'] ?? $hue);
                    @endphp
                    @if (filled($item['url'] ?? null))
                        <a class="mf-row mf-s{{ $rowSlot }}" href="{{ $item['url'] }}">
                    @else
                        <g class="mf-row mf-s{{ $rowSlot }}">
                    @endif
                        <rect class="mf-hit-area" x="0" y="{{ $y }}" width="{{ $W }}" height="{{ $rowH }}" />
                        <text class="mf-label" x="{{ $labelW }}" y="{{ $c($mid) }}" text-anchor="end" dominant-baseline="middle">{{ \Illuminate\Support\Str::limit($item['label'], 30) }}</text>
                        @if (array_key_exists('capacity', $item) && $item['capacity'] !== null && $item['capacity'] > 0)
                            <path class="mf-track" d="{{ $shape($barX, $mid - $thick / 2, max(2, $len($item['capacity'])), $thick) }}" />
                        @endif
                        @if ($length > 0)
                            <path class="mf-bar" d="{{ $shape($barX, $mid - $thick / 2, max(2, $length), $thick) }}" />
                        @endif
                        <text class="mf-value" x="{{ $c($barX + max($length, array_key_exists('capacity', $item) && $item['capacity'] ? $len($item['capacity']) : 0) + 6) }}" y="{{ $c($mid) }}" dominant-baseline="middle">{{ $display($item) }}</text>
                        <title>{{ $tip }}</title>
                    @if (filled($item['url'] ?? null))
                        </a>
                    @else
                        </g>
                    @endif
                @endforeach
            </svg>
        </div>

        <x-charts.data-table
            :caption="$title"
            :headers="[$labelHeading, $valueHeading]"
            :rows="array_map(fn ($item) => [$item['label'].(filled($item['hint'] ?? null) ? ' ('.$item['hint'].')' : ''), $display($item)], $items)"
        />
    @endif
</figure>
