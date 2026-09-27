{{--
    Which colour is which series. Always present for two or more series, so
    identity never rests on matching colours alone; a single series needs
    none — the chart's title already names it.

    items: list<array{name: string, slot: int}>
--}}
@props(['items' => [], 'line' => false])

@if (count($items) >= 2)
    <ul class="mf-legend">
        @foreach ($items as $item)
            <li class="mf-s{{ (int) ($item['slot'] ?? 1) }}">
                <span @class(['mf-swatch', 'mf-swatch-line' => $line]) aria-hidden="true"></span>
                <span>{{ $item['name'] }}</span>
            </li>
        @endforeach
    </ul>
@endif
