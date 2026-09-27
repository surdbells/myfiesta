{{--
    Part of a whole: a ring whose segments add up to the figure in its middle.

    segments: list<array{label: string, value: int|float, slot?: int}>  — at most six; more fold into "Other"
    format:   money (minor units, with currency) | number

    Segments start at twelve o'clock and run clockwise in the order given,
    separated by a 2px gap in the surface colour. The legend beside it gives
    every segment's value and share, so the ring is never read by colour
    alone.
--}}
@props([
    'title',
    'description' => null,
    'segments' => [],
    'format' => 'number',
    'currency' => null,
    'centerLabel' => 'Total',
    'empty' => 'Nothing to show for this period.',
])

@php
    $F = \App\Services\Analytics\Charts\Format::class;

    $segments = array_values($segments);

    // Past six, the rest become one gray "Other" rather than a seventh hue.
    if (count($segments) > 6) {
        $rest = array_slice($segments, 5);
        $segments = array_slice($segments, 0, 5);
        $segments[] = ['label' => 'Other', 'value' => array_sum(array_column($rest, 'value')), 'slot' => 0];
    }

    $total = array_sum(array_map(fn ($s) => max(0, (float) $s['value']), $segments));
    $hasData = $total > 0;

    $size = 200;
    $cx = 100;
    $cy = 100;
    $R = 92;
    $r = 60;
    $c = fn (float $v) => $F::coord($v);
    $point = fn (float $radius, float $angle) => [$cx + $radius * cos($angle), $cy + $radius * sin($angle)];

    // Each segment is drawn as two arcs split at its middle, so a segment
    // that is the whole ring still has a start and an end that differ.
    $arc = function (float $a0, float $a1) use ($R, $r, $point, $c): string {
        $mid = ($a0 + $a1) / 2;
        [$o0, $o1, $o2] = [$point($R, $a0), $point($R, $mid), $point($R, $a1)];
        [$i0, $i1, $i2] = [$point($r, $a0), $point($r, $mid), $point($r, $a1)];

        return 'M'.$c($o0[0]).' '.$c($o0[1])
            .' A'.$R.' '.$R.' 0 0 1 '.$c($o1[0]).' '.$c($o1[1])
            .' A'.$R.' '.$R.' 0 0 1 '.$c($o2[0]).' '.$c($o2[1])
            .' L'.$c($i2[0]).' '.$c($i2[1])
            .' A'.$r.' '.$r.' 0 0 0 '.$c($i1[0]).' '.$c($i1[1])
            .' A'.$r.' '.$r.' 0 0 0 '.$c($i0[0]).' '.$c($i0[1]).' Z';
    };

    $drawn = [];
    $angle = -M_PI / 2;
    foreach ($segments as $index => $segment) {
        $value = max(0, (float) $segment['value']);
        if ($value <= 0) {
            continue;
        }
        $sweep = $value / $total * 2 * M_PI;
        $drawn[] = [
            'slot' => (int) ($segment['slot'] ?? $index + 1),
            'd' => $arc($angle, $angle + $sweep),
            'tip' => $segment['label'].': '.$F::value($segment['value'], $format, $currency).' ('.$F::percent($value / $total).')',
        ];
        $angle += $sweep;
    }

    $uid = 'mfd-'.substr(md5($title.json_encode($segments)), 0, 10);

    $summary = $description ?? $centerLabel.' '.$F::value($total, $format, $currency).'. '.collect($segments)
        ->map(fn ($s) => $s['label'].' '.$F::value($s['value'], $format, $currency).' ('.$F::percent($total > 0 ? max(0, $s['value']) / $total : null).')')
        ->implode('; ').'.';
@endphp

<figure {{ $attributes->class(['mf-chart']) }} data-chart="donut">
    @if (! $hasData)
        <div class="mf-empty" role="note">{{ $empty }}</div>
    @else
        <div class="mf-donut">
            <svg viewBox="0 0 {{ $size }} {{ $size }}" role="img" aria-labelledby="{{ $uid }}-t {{ $uid }}-d" xmlns="http://www.w3.org/2000/svg">
                <title id="{{ $uid }}-t">{{ $title }}</title>
                <desc id="{{ $uid }}-d">{{ $summary }}</desc>
                @foreach ($drawn as $segment)
                    <path class="mf-seg mf-s{{ $segment['slot'] }}" d="{{ $segment['d'] }}"><title>{{ $segment['tip'] }}</title></path>
                @endforeach
                <text class="mf-center-value" x="{{ $cx }}" y="{{ $cy - 2 }}" text-anchor="middle" aria-hidden="true">{{ $F::value($total, $format, $currency, compact: true) }}</text>
                <text class="mf-center-label" x="{{ $cx }}" y="{{ $cy + 16 }}" text-anchor="middle" aria-hidden="true">{{ $centerLabel }}</text>
            </svg>

            <ul class="mf-legend">
                @foreach ($segments as $index => $segment)
                    <li class="mf-s{{ (int) ($segment['slot'] ?? $index + 1) }}">
                        <span class="mf-swatch" aria-hidden="true"></span>
                        <span>{{ $segment['label'] }}</span>
                        <span class="mf-num">{{ $F::value($segment['value'], $format, $currency) }}</span>
                        <span class="mf-share">{{ $F::percent(max(0, $segment['value']) / $total) }}</span>
                    </li>
                @endforeach
            </ul>
        </div>

        <x-charts.data-table
            :caption="$title"
            :headers="['', 'Value', 'Share']"
            :rows="array_merge(
                array_map(fn ($s) => [$s['label'], $F::value($s['value'], $format, $currency), $F::percent(max(0, $s['value']) / $total)], $segments),
                [[$centerLabel, $F::value($total, $format, $currency), '100.0%']],
            )"
        />
    @endif
</figure>
