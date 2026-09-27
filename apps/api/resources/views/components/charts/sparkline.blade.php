{{--
    A trend in the space of a word: no axes, no labels, the shape only.

    Beside a figure that already states the value, so the line only has to
    say whether it has been rising or falling. The last point is marked, since
    that is the one the figure beside it describes.

    values: list<int|float>
--}}
@props(['values' => [], 'label' => 'Trend', 'hue' => 1, 'width' => 88, 'height' => 28])

@php
    $F = \App\Services\Analytics\Charts\Format::class;

    $values = array_values(array_map('floatval', $values));
    $n = count($values);
    $pad = 3;
    $max = $n ? max($values) : 0;
    $min = $n ? min(0, min($values)) : 0;
    $span = $max - $min ?: 1;
    $x = fn (int $i) => $n <= 1 ? $width / 2 : $pad + $i * ($width - 2 * $pad) / ($n - 1);
    $y = fn (float $v) => $height - $pad - ($v - $min) / $span * ($height - 2 * $pad);
    $direction = $n < 2 ? "flat" : ($values[$n - 1] > $values[0] ? "ending higher than it started" : ($values[$n - 1] < $values[0] ? "ending lower than it started" : "ending where it started"));
    $points = array_map(fn ($v, $i) => $F::coord($x($i)).' '.$F::coord($y($v)), $values, array_keys($values));
@endphp

@if ($n >= 2)
    <svg {{ $attributes->class(['mf-spark', 'mf-s'.$hue]) }} viewBox="0 0 {{ $width }} {{ $height }}" role="img" aria-label="{{ $label }} trend over {{ $n }} points: {{ $direction }}" xmlns="http://www.w3.org/2000/svg" data-chart="sparkline">
        <path class="mf-area" d="M{{ implode(' L', $points) }} L{{ $F::coord($x($n - 1)) }} {{ $F::coord($y($min)) }} L{{ $F::coord($x(0)) }} {{ $F::coord($y($min)) }} Z" />
        <path class="mf-line" d="M{{ implode(' L', $points) }}" />
        <circle class="mf-end" cx="{{ $F::coord($x($n - 1)) }}" cy="{{ $F::coord($y($values[$n - 1])) }}" r="2.5" />
    </svg>
@endif
