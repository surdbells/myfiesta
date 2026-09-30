{{--
    A box that scrolls sideways and draws no scrollbar (.mf-scroll and
    .mf-table-x in the styles): a chart narrower than its smallest, a data
    table wider than a phone.

    A trackpad, a finger and Shift with the wheel scroll it as they did with a
    bar. A keyboard reached what was past the edge only by dragging the bar,
    and a chart has nothing in it to tab to, so while anything is past the
    edge the box takes a tab stop and the arrow keys scroll it. Only then: at
    a desk the charts fit, and a stop that moves nothing is one more press
    between somebody and the next control. Measured by Alpine, which every
    panel page already runs, when the box first draws and whenever it changes
    size — the window, the sidebar, a data table opened.

    A named region, so that a screen reader says what the stop is.

    label: what the box holds, as a screen reader should name it
--}}
@props(['label'])

<div
    {{ $attributes->merge(['role' => 'region', 'aria-label' => $label]) }}
    x-data="{ scrolls: false }"
    x-init="const box = $el, measure = () => scrolls = box.scrollWidth > box.clientWidth + 1; measure(); new ResizeObserver(measure).observe(box)"
    x-bind:tabindex="scrolls ? 0 : null"
>{{ $slot }}</div>
