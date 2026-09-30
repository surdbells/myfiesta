{{--
    Columns: one per label, grouped side by side or stacked.

    series:  list<array{name: string, values: list<int|float>, slot?: int}>  — at most six
    labels:  list<string>
    stacked: stack the series in each column instead of standing them side by side
    format:  money (minor units, with currency) | number | percent

    Bars are at most 24px wide, square at the baseline and rounded at the data
    end; stacked segments are separated by a 2px gap in the surface colour
    rather than by an outline. Every bar carries its own tooltip, and the
    column around it one that lists the whole column.
--}}
@props([
    'title',
    'description' => null,
    'labels' => [],
    'series' => [],
    'stacked' => false,
    'format' => 'number',
    'currency' => null,
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
    $k = count($series);

    $W = 640;
    $H = (int) $height;
    $pl = 64;
    $pr = 16;
    $pt = 18;
    $pb = 28;
    $pw = $W - $pl - $pr;
    $ph = $H - $pt - $pb;
    $gap = 2;

    $toUnits = fn ($v) => match ($format) { 'money' => $v / 100, 'percent' => $v * 100, default => (float) $v };
    $fromUnits = fn ($u) => match ($format) { 'money' => $u * 100, 'percent' => $u / 100, default => $u };
    $value = fn (array $s, int $i) => max(0, (float) ($s['values'][$i] ?? 0));

    $max = 0.0;
    $hasData = false;
    for ($i = 0; $i < $n; $i++) {
        $column = 0.0;
        foreach ($series as $s) {
            $v = $value($s, $i);
            $hasData = $hasData || $v > 0;
            $column = $stacked ? $column + $toUnits($v) : max($column, $toUnits($v));
        }
        $max = max($max, $column);
    }

    $scale = $S::nice($max, 4, integer: $format === 'number');
    $baseline = $pt + $ph;
    $band = $n > 0 ? $pw / $n : $pw;
    $h = fn ($v) => ($toUnits($v) / $scale['max']) * $ph;
    $c = fn (float $v) => $F::coord($v);

    $barWidth = $stacked || $k <= 1
        ? min(24, max(2, $band * 0.6))
        : min(24, max(2, ($band * 0.72 - ($k - 1) * $gap) / max(1, $k)));

    // A rectangle whose top corners are rounded and whose bottom is square.
    $shape = function (float $x, float $top, float $w, float $height, bool $rounded) use ($c): string {
        $r = $rounded ? min(4, $w / 2, $height) : 0;
        $bottom = $top + $height;

        return 'M'.$c($x).' '.$c($bottom)
            .' L'.$c($x).' '.$c($top + $r)
            .($r > 0 ? ' Q'.$c($x).' '.$c($top).' '.$c($x + $r).' '.$c($top) : '')
            .' L'.$c($x + $w - $r).' '.$c($top)
            .($r > 0 ? ' Q'.$c($x + $w).' '.$c($top).' '.$c($x + $w).' '.$c($top + $r) : '')
            .' L'.$c($x + $w).' '.$c($bottom).' Z';
    };

    $bars = [];
    for ($i = 0; $i < $n; $i++) {
        $center = $pl + $band * $i + $band / 2;

        if ($stacked) {
            $top = $baseline;
            $visible = array_values(array_filter(array_keys($series), fn ($j) => $value($series[$j], $i) > 0));
            foreach ($visible as $position => $j) {
                $height = $h($value($series[$j], $i));
                // The gap is taken out of the segment above it, so the column
                // still ends at its true total.
                $drawn = $position > 0 ? max(0, $height - $gap) : $height;
                $top -= $height;
                if ($drawn <= 0) {
                    continue;
                }
                $bars[] = [
                    'slot' => (int) ($series[$j]['slot'] ?? $j + 1),
                    'd' => $shape($center - $barWidth / 2, $top, $barWidth, $drawn, $position === count($visible) - 1),
                    'tip' => $labels[$i].' · '.$series[$j]['name'].': '.$F::value($series[$j]['values'][$i] ?? 0, $format, $currency),
                ];
            }
        } else {
            $groupWidth = $k * $barWidth + ($k - 1) * $gap;
            foreach ($series as $j => $s) {
                $v = $value($s, $i);
                if ($v <= 0) {
                    continue;
                }
                $height = $h($v);
                $bars[] = [
                    'slot' => (int) ($s['slot'] ?? $j + 1),
                    'd' => $shape($center - $groupWidth / 2 + $j * ($barWidth + $gap), $baseline - $height, $barWidth, $height, true),
                    'tip' => $labels[$i].' · '.$s['name'].': '.$F::value($s['values'][$i] ?? 0, $format, $currency),
                ];
            }
        }
    }

    $uid = 'mfb-'.substr(md5($title.json_encode($labels).json_encode($series).($stacked ? 's' : 'g')), 0, 10);

    $summary = $description ?? collect($series)->map(function ($s) use ($labels, $F, $format, $currency) {
        $values = array_values($s['values']);
        if ($values === []) {
            return $s['name'].': no data';
        }
        $peak = array_keys($values, max($values))[0];

        return $s['name'].': total '.$F::value(array_sum($values), $format, $currency)
            .', highest '.$F::value($values[$peak], $format, $currency).' ('.($labels[$peak] ?? '').')';
    })->implode('. ').'.';
@endphp

<figure {{ $attributes->class(['mf-chart']) }} data-chart="{{ $stacked ? 'stacked-bar' : 'grouped-bar' }}">
    @if (! $hasData || $n === 0)
        <div class="mf-empty" role="note">{{ $empty }}</div>
    @else
        <x-charts.scroll-region class="mf-scroll mf-scroll-latest" :label="$title">
            <svg viewBox="0 0 {{ $W }} {{ $H }}" role="img" aria-labelledby="{{ $uid }}-t {{ $uid }}-d" preserveAspectRatio="xMidYMid meet" xmlns="http://www.w3.org/2000/svg">
                <title id="{{ $uid }}-t">{{ $title }}</title>
                <desc id="{{ $uid }}-d">{{ $summary }}</desc>

                <g aria-hidden="true">
                    @foreach ($scale['ticks'] as $tick)
                        @php $ty = $baseline - ($tick / $scale['max']) * $ph; @endphp
                        <line class="{{ $tick == 0 ? 'mf-axis' : 'mf-grid' }}" x1="{{ $pl }}" x2="{{ $W - $pr }}" y1="{{ $c($ty) }}" y2="{{ $c($ty) }}" />
                        <text class="mf-tick" x="{{ $pl - 8 }}" y="{{ $c($ty) }}" text-anchor="end" dominant-baseline="middle">{{ $F::value($fromUnits($tick), $format, $currency, compact: true) }}</text>
                    @endforeach

                    @foreach ($S::labelIndexes($n, 8) as $i)
                        <text class="mf-tick" x="{{ $c($pl + $band * $i + $band / 2) }}" y="{{ $H - 8 }}" text-anchor="middle">{{ $labels[$i] }}</text>
                    @endforeach
                </g>

                <g class="mf-bands">
                    @for ($i = 0; $i < $n; $i++)
                        <g class="mf-band">
                            <rect class="mf-hit-area" x="{{ $c($pl + $band * $i) }}" y="{{ $pt }}" width="{{ $c($band) }}" height="{{ $ph }}" />
                            <title>{{ $labels[$i] }} — {{ collect($series)->map(fn ($s) => $s['name'].': '.$F::value($s['values'][$i] ?? 0, $format, $currency))->implode('; ') }}</title>
                        </g>
                    @endfor
                </g>

                <g class="mf-bars">
                    @foreach ($bars as $bar)
                        <path class="mf-bar mf-s{{ $bar['slot'] }}" d="{{ $bar['d'] }}"><title>{{ $bar['tip'] }}</title></path>
                    @endforeach
                </g>
            </svg>
        </x-charts.scroll-region>

        <x-charts.legend :items="array_map(fn ($s, $i) => ['name' => $s['name'], 'slot' => $s['slot'] ?? $i + 1], $series, array_keys($series))" />

        <x-charts.data-table
            :caption="$title"
            :headers="array_merge([$xLabel], array_column($series, 'name'))"
            :rows="array_map(fn ($i) => array_merge([$labels[$i]], array_map(fn ($s) => $F::value($s['values'][$i] ?? 0, $format, $currency), $series)), range(0, $n - 1))"
        />
    @endif
</figure>
