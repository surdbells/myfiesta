{{--
    A line (or area) over time.

    series:  list<array{name: string, values: list<int|float>, slot?: int}>  — at most six
    labels:  list<string>   one per point, e.g. "Sep 4"
    format:  money (minor units, with currency) | number | percent (ratios 0–1)

    One y-axis, always from zero. Hovering a column of the chart shows every
    series' value at that point (a crosshair, and a <title> tooltip); the data
    table underneath carries the same numbers for anybody not using a mouse.
--}}
@props([
    'title',
    'description' => null,
    'labels' => [],
    'series' => [],
    'format' => 'number',
    'currency' => null,
    'area' => false,
    'height' => 240,
    'empty' => 'Nothing to show for this period.',
    'xLabel' => 'Period',
])

@php
    $F = \App\Services\Analytics\Charts\Format::class;
    $S = \App\Services\Analytics\Charts\Scale::class;

    $series = array_values(array_slice($series, 0, 6));
    $labels = array_values($labels);
    $n = count($labels);

    $W = 640;
    $H = (int) $height;
    $pl = 64;
    $pr = 16;
    $pt = 18;
    $pb = 28;
    $pw = $W - $pl - $pr;
    $ph = $H - $pt - $pb;

    $toUnits = fn ($v) => match ($format) { 'money' => $v / 100, 'percent' => $v * 100, default => (float) $v };
    $fromUnits = fn ($u) => match ($format) { 'money' => $u * 100, 'percent' => $u / 100, default => $u };

    $max = 0.0;
    $hasData = false;
    foreach ($series as $s) {
        foreach ($s['values'] as $v) {
            $max = max($max, $toUnits($v));
            $hasData = $hasData || (float) $v != 0.0;
        }
    }
    $hasData = $hasData && $n > 0;

    $scale = $S::nice($max, 4, integer: $format === 'number');
    $baseline = $pt + $ph;
    $x = fn (int $i) => $n <= 1 ? $pl + $pw / 2 : $pl + $i * $pw / ($n - 1);
    $y = fn ($v) => $baseline - ($toUnits($v) / $scale['max']) * $ph;
    $c = fn (float $v) => $F::coord($v);
    $band = $n <= 1 ? $pw : $pw / ($n - 1);

    $uid = 'mfl-'.substr(md5($title.json_encode($labels).json_encode($series)), 0, 10);

    $summary = $description ?? collect($series)->map(function ($s) use ($labels, $F, $format, $currency) {
        $values = array_values($s['values']);
        if ($values === []) {
            return $s['name'].': no data';
        }
        $peak = array_keys($values, max($values))[0];

        return $s['name'].': total '.$F::value(array_sum($values), $format, $currency)
            .', highest '.$F::value($values[$peak], $format, $currency).' ('.($labels[$peak] ?? '').')';
    })->implode('. ').'.';

    $paths = [];
    foreach ($series as $index => $s) {
        $points = [];
        foreach (array_values($s['values']) as $i => $v) {
            $points[] = [$x($i), $y($v)];
        }
        $d = $points === [] ? '' : 'M'.implode(' L', array_map(fn ($p) => $c($p[0]).' '.$c($p[1]), $points));
        $paths[] = [
            'slot' => (int) ($s['slot'] ?? $index + 1),
            'line' => $d,
            'area' => $points === [] ? '' : $d.' L'.$c(end($points)[0]).' '.$c($baseline).' L'.$c($points[0][0]).' '.$c($baseline).' Z',
            'end' => end($points) ?: null,
        ];
    }

    // One annotation, on a single series: its peak. A number on every point
    // is noise; the peak is the one a reader looks for.
    $peak = null;
    if (count($series) === 1 && $hasData) {
        $values = array_values($series[0]['values']);
        $i = array_keys($values, max($values))[0];
        $peak = ['x' => $x($i), 'y' => $y($values[$i]) - 10, 'text' => $F::value($values[$i], $format, $currency, compact: true)];
        $peak['anchor'] = $peak['x'] > $W - $pr - 40 ? 'end' : ($peak['x'] < $pl + 40 ? 'start' : 'middle');
    }
@endphp

<figure {{ $attributes->class(['mf-chart']) }} data-chart="line">
    @if (! $hasData)
        <div class="mf-empty" role="note">{{ $empty }}</div>
    @else
        <div class="mf-scroll mf-scroll-latest">
            <svg viewBox="0 0 {{ $W }} {{ $H }}" role="img" aria-labelledby="{{ $uid }}-t {{ $uid }}-d" preserveAspectRatio="xMidYMid meet" xmlns="http://www.w3.org/2000/svg">
                <title id="{{ $uid }}-t">{{ $title }}</title>
                <desc id="{{ $uid }}-d">{{ $summary }}</desc>

                <g aria-hidden="true">
                    @foreach ($scale['ticks'] as $tick)
                        @php $ty = $baseline - ($tick / $scale['max']) * $ph; @endphp
                        <line class="{{ $tick == 0 ? 'mf-axis' : 'mf-grid' }}" x1="{{ $pl }}" x2="{{ $W - $pr }}" y1="{{ $c($ty) }}" y2="{{ $c($ty) }}" />
                        <text class="mf-tick" x="{{ $pl - 8 }}" y="{{ $c($ty) }}" text-anchor="end" dominant-baseline="middle">{{ $F::value($fromUnits($tick), $format, $currency, compact: true) }}</text>
                    @endforeach

                    @foreach ($S::labelIndexes($n, 7) as $i)
                        <text class="mf-tick" x="{{ $c($x($i)) }}" y="{{ $H - 8 }}" text-anchor="{{ $n > 1 && $i === 0 ? 'start' : ($n > 1 && $i === $n - 1 ? 'end' : 'middle') }}">{{ $labels[$i] }}</text>
                    @endforeach
                </g>

                @foreach ($paths as $path)
                    <g class="mf-series mf-s{{ $path['slot'] }}" aria-hidden="true">
                        @if ($area)
                            <path class="mf-area" d="{{ $path['area'] }}" />
                        @endif
                        <path class="mf-line" d="{{ $path['line'] }}" />
                        @if ($path['end'])
                            <circle class="mf-end" cx="{{ $c($path['end'][0]) }}" cy="{{ $c($path['end'][1]) }}" r="4" />
                        @endif
                    </g>
                @endforeach

                @if ($peak)
                    <text class="mf-value" x="{{ $c($peak['x']) }}" y="{{ $c(max($peak['y'], 10)) }}" text-anchor="{{ $peak['anchor'] }}" aria-hidden="true">{{ $peak['text'] }}</text>
                @endif

                <g class="mf-hits">
                    @for ($i = 0; $i < $n; $i++)
                        @php
                            $hx = max($pl, $x($i) - $band / 2);
                            $hw = min($W - $pr, $x($i) + $band / 2) - $hx;
                        @endphp
                        <g class="mf-hit">
                            <rect class="mf-hit-area" x="{{ $c($hx) }}" y="{{ $pt }}" width="{{ $c(max($hw, 1)) }}" height="{{ $ph }}" />
                            <line class="mf-crosshair" x1="{{ $c($x($i)) }}" x2="{{ $c($x($i)) }}" y1="{{ $pt }}" y2="{{ $baseline }}" />
                            @foreach ($series as $index => $s)
                                <circle class="mf-dot mf-s{{ (int) ($s['slot'] ?? $index + 1) }}" cx="{{ $c($x($i)) }}" cy="{{ $c($y($s['values'][$i] ?? 0)) }}" r="4" />
                            @endforeach
                            <title>{{ $labels[$i] }} — {{ collect($series)->map(fn ($s) => $s['name'].': '.$F::value($s['values'][$i] ?? 0, $format, $currency))->implode('; ') }}</title>
                        </g>
                    @endfor
                </g>
            </svg>
        </div>

        <x-charts.legend :items="array_map(fn ($s, $i) => ['name' => $s['name'], 'slot' => $s['slot'] ?? $i + 1], $series, array_keys($series))" line />

        <x-charts.data-table
            :caption="$title"
            :headers="array_merge([$xLabel], array_column($series, 'name'))"
            :rows="array_map(fn ($i) => array_merge([$labels[$i]], array_map(fn ($s) => $F::value($s['values'][$i] ?? 0, $format, $currency), $series)), range(0, $n - 1))"
        />
    @endif
</figure>
