{{--
    The chart kit's look, in one place.

    Colours are Filament's own theme palette. Slot 1 is the panel's primary
    (amber) and slot 2 its info blue, read from the CSS variables Filament
    writes on every page, so a change of panel colour follows through; slots
    3 to 6 are Filament palette shades the panel does not register as
    variables, taken from the same colour constants.

    The order is the colour-blind-safety mechanism, not decoration: this
    sequence was run through the categorical palette validator against the
    panel's own surfaces (white, and gray-900 in dark mode) and passes every
    check on adjacent pairs in both modes. A seventh series is never given a
    new hue; it folds into "Other", which is gray.

    Dark mode is chosen, not flipped: each slot uses the shade that sits in
    the dark lightness band, and ink, grid and track take darker grays.
--}}
@php
    $c = \Filament\Support\Colors\Color::class;

    $light = [
        1 => 'var(--primary-600, '.$c::Amber[600].')',
        2 => 'var(--info-600, '.$c::Blue[600].')',
        3 => $c::Teal[600],
        4 => $c::Violet[600],
        5 => $c::Pink[600],
        6 => $c::Sky[600],
    ];

    $dark = [
        1 => 'var(--primary-600, '.$c::Amber[600].')',
        2 => 'var(--info-500, '.$c::Blue[500].')',
        3 => $c::Teal[600],
        4 => $c::Violet[500],
        5 => $c::Pink[500],
        6 => $c::Sky[600],
    ];
@endphp
<style data-mf-charts>
    :root {
        --mf-surface: #fff;
        --mf-ink: var(--gray-950, #09090b);
        --mf-ink-2: var(--gray-600, #52525b);
        --mf-muted: var(--gray-500, #71717a);
        --mf-grid: var(--gray-200, #e4e4e7);
        --mf-axis: var(--gray-300, #d4d4d8);
        --mf-track: var(--gray-100, #f4f4f5);
        --mf-border: var(--gray-200, #e4e4e7);
        --mf-other: var(--gray-400, #9f9fa9);
        --mf-good: var(--success-700, #008236);
        --mf-bad: var(--danger-700, #c10007);
        @foreach ($light as $slot => $colour) --mf-s{{ $slot }}: {{ $colour }}; @endforeach
    }

    :root.dark {
        --mf-surface: var(--gray-900, #18181b);
        --mf-ink: #fff;
        --mf-ink-2: var(--gray-300, #d4d4d8);
        --mf-muted: var(--gray-400, #9f9fa9);
        --mf-grid: var(--gray-800, #27272a);
        --mf-axis: var(--gray-700, #3f3f46);
        --mf-track: var(--gray-800, #27272a);
        --mf-border: rgb(255 255 255 / 0.1);
        --mf-other: var(--gray-500, #71717b);
        --mf-good: var(--success-400, #05df72);
        --mf-bad: var(--danger-400, #ff6467);
        @foreach ($dark as $slot => $colour) --mf-s{{ $slot }}: {{ $colour }}; @endforeach
    }

    .mf-s0 { --mf-c: var(--mf-other); }
    @foreach (array_keys($light) as $slot) .mf-s{{ $slot }} { --mf-c: var(--mf-s{{ $slot }}); } @endforeach

    .mf-chart { position: relative; margin: 0; color: var(--mf-ink-2); min-width: 0; }
    /* A chart narrower than its smallest scrolls sideways, and so does a data
       table wider than a phone; neither draws a scrollbar while it does. The
       bars or the column cut off at the edge say there is more, and a
       trackpad, a finger or Shift with the wheel reach it. A keyboard reaches
       it through the tab stop the box takes while it scrolls (scroll-region),
       with its ring drawn inside, where the section around it cannot clip it.
       Filament's own tables and tabs lose theirs in the admin layout
       (filament/admin-layout). */
    .mf-scroll, .mf-table-x { overflow-x: auto; scrollbar-width: none; }
    .mf-scroll::-webkit-scrollbar, .mf-table-x::-webkit-scrollbar { display: none; }
    .mf-scroll:focus-visible, .mf-table-x:focus-visible { outline: 2px solid var(--mf-s2); outline-offset: -2px; }
    .mf-chart svg { display: block; width: 100%; height: auto; font-family: inherit; overflow: visible; }
    .mf-scroll > svg { min-width: 28rem; }
    /* A series over time, when it has to scroll: opened at its latest end,
       which is where the figures somebody came for are. */
    .mf-scroll-latest { display: flex; flex-direction: row-reverse; }
    .mf-scroll-latest > svg { flex: none; }
    .mf-grid { stroke: var(--mf-grid); stroke-width: 1; shape-rendering: crispEdges; }
    .mf-axis { stroke: var(--mf-axis); stroke-width: 1; shape-rendering: crispEdges; }
    .mf-tick { fill: var(--mf-muted); font-size: 11px; font-variant-numeric: tabular-nums; }
    .mf-label { fill: var(--mf-ink-2); font-size: 12px; }
    .mf-value { fill: var(--mf-ink); font-size: 12px; font-weight: 600; font-variant-numeric: tabular-nums; }
    .mf-center-value { fill: var(--mf-ink); font-size: 18px; font-weight: 600; }
    .mf-center-label { fill: var(--mf-muted); font-size: 11px; }
    .mf-line { fill: none; stroke: var(--mf-c); stroke-width: 2; stroke-linejoin: round; stroke-linecap: round; }
    .mf-area { fill: var(--mf-c); opacity: 0.1; }
    .mf-bar, .mf-seg { fill: var(--mf-c); }
    .mf-seg { stroke: var(--mf-surface); stroke-width: 2; stroke-linejoin: round; }
    .mf-track { fill: var(--mf-track); }
    .mf-end, .mf-dot { fill: var(--mf-c); stroke: var(--mf-surface); stroke-width: 2; }
    .mf-hit-area { fill: transparent; pointer-events: all; }
    .mf-crosshair { stroke: var(--mf-axis); stroke-width: 1; opacity: 0; }
    .mf-hit .mf-dot { opacity: 0; }
    .mf-hit:hover .mf-crosshair, .mf-hit:hover .mf-dot { opacity: 1; }
    .mf-bar:hover, .mf-seg:hover, .mf-row:hover .mf-bar { opacity: 0.8; }
    .mf-band:hover .mf-hit-area, .mf-row:hover .mf-hit-area { fill: var(--mf-grid); fill-opacity: 0.35; }
    a.mf-row:focus-visible { outline: 2px solid var(--mf-s2); outline-offset: 1px; }

    /* A ranking, drawn in HTML so its words are the page's size at any width
       (see hbar). The label beside its bar where there is room, and on its
       own line above it where there is not: never scrolled sideways, because
       the values at the tips are what a ranking is read for. */
    .mf-hbar { container-type: inline-size; margin: 0; padding: 0; list-style: none; }
    .mf-hbar .mf-row { display: grid; grid-template-columns: minmax(0, min(30%, 12.5rem)) minmax(0, 1fr); align-items: center; column-gap: 0.75rem; min-height: 1.875rem; padding: 0 0.25rem; border-radius: 0.25rem; color: inherit; text-decoration: none; }
    .mf-hbar .mf-row:hover { background: color-mix(in srgb, var(--mf-grid) 35%, transparent); }
    .mf-hbar-label { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; text-align: right; font-size: 0.75rem; color: var(--mf-ink-2); }
    .mf-hbar-line { display: flex; align-items: center; gap: 0.375rem; min-width: 0; align-self: stretch; border-left: 1px solid var(--mf-axis); }
    .mf-hbar-bars { position: relative; flex: 0 1 auto; min-width: 0; width: calc((100% - 7.5rem) * var(--mf-reach)); height: 0.875rem; }
    .mf-hbar .mf-track, .mf-hbar .mf-bar { position: absolute; top: 0; bottom: 0; left: 0; border-radius: 0 4px 4px 0; }
    .mf-hbar .mf-track { background: var(--mf-track); }
    .mf-hbar .mf-bar { min-width: 2px; background: var(--mf-c); }
    .mf-hbar .mf-value { flex: none; font-size: 0.75rem; font-weight: 600; font-variant-numeric: tabular-nums; color: var(--mf-ink); white-space: nowrap; }
    @container (width < 28rem) {
        .mf-hbar .mf-row { grid-template-columns: minmax(0, 1fr); row-gap: 0.125rem; padding-block: 0.25rem; }
        .mf-hbar-label { text-align: left; }
        .mf-hbar-line { min-height: 1.125rem; }
        .mf-hbar-bars { width: calc((100% - 6.5rem) * var(--mf-reach)); }
    }

    .mf-legend { display: flex; flex-wrap: wrap; gap: 0.25rem 1rem; margin: 0.5rem 0 0; padding: 0; list-style: none; font-size: 0.8125rem; color: var(--mf-ink-2); }
    .mf-legend li { display: inline-flex; align-items: center; gap: 0.375rem; }
    .mf-swatch { display: inline-block; width: 0.625rem; height: 0.625rem; border-radius: 2px; background: var(--mf-c); flex: none; }
    .mf-swatch-line { height: 2px; width: 0.875rem; border-radius: 1px; }

    .mf-donut { display: flex; flex-wrap: wrap; align-items: center; gap: 1rem 1.5rem; }
    .mf-donut > svg { width: 11rem; max-width: 100%; flex: none; }
    .mf-donut .mf-legend { flex-direction: column; gap: 0.375rem; flex: 1 1 12rem; margin: 0; }
    .mf-donut .mf-legend li { display: grid; grid-template-columns: auto 1fr auto auto; gap: 0.5rem; align-items: center; }
    .mf-num { font-variant-numeric: tabular-nums; text-align: right; color: var(--mf-ink); }
    .mf-share { font-variant-numeric: tabular-nums; text-align: right; color: var(--mf-muted); min-width: 3.25rem; }

    .mf-data { margin-top: 0.5rem; font-size: 0.8125rem; color: var(--mf-ink-2); }
    .mf-data summary { cursor: pointer; color: var(--mf-muted); width: max-content; }
    .mf-data summary:hover { color: var(--mf-ink-2); }
    /* Two boxes, because one cannot hide one scrollbar and keep the other:
       the outer scrolls down and keeps its bar, which says how long the
       table is; the inner scrolls a table wider than a phone sideways and
       draws none, like a chart (.mf-table-x, above). */
    .mf-table-wrap { max-height: 20rem; overflow-y: auto; margin-top: 0.5rem; }
    .mf-data table { width: 100%; border-collapse: collapse; font-variant-numeric: tabular-nums; }
    .mf-data th, .mf-data td { text-align: left; padding: 0.25rem 0.5rem; border-bottom: 1px solid var(--mf-grid); }
    .mf-data th { color: var(--mf-muted); font-weight: 500; }
    .mf-data .mf-num { text-align: right; }

    .mf-empty { padding: 2rem 1rem; text-align: center; color: var(--mf-muted); font-size: 0.875rem; }
    .mf-note { font-size: 0.75rem; color: var(--mf-muted); margin-top: 0.5rem; }

    .mf-kpis { display: grid; grid-template-columns: repeat(auto-fill, minmax(12.5rem, 1fr)); gap: 0.75rem; }
    .mf-stat { border: 1px solid var(--mf-border); border-radius: 0.75rem; padding: 0.875rem 1rem; background: var(--mf-surface); display: flex; flex-direction: column; gap: 0.25rem; min-width: 0; }
    a.mf-stat:hover { border-color: var(--mf-axis); }
    .mf-stat-label { font-size: 0.8125rem; color: var(--mf-ink-2); }
    .mf-stat-value { font-size: 1.375rem; font-weight: 600; color: var(--mf-ink); line-height: 1.25; overflow-wrap: anywhere; }
    .mf-stat-foot { display: flex; align-items: flex-end; justify-content: space-between; gap: 0.5rem; font-size: 0.75rem; color: var(--mf-muted); min-height: 1.75rem; }
    .mf-delta { font-weight: 600; white-space: nowrap; }
    .mf-delta-good { color: var(--mf-good); }
    .mf-delta-bad { color: var(--mf-bad); }
    .mf-delta-flat { color: var(--mf-muted); }
    .mf-spark { width: 5.5rem; height: 1.75rem; flex: none; overflow: visible; }
    .mf-spark .mf-line { stroke-width: 1.5; }

    .mf-stack { display: grid; gap: 1.5rem; grid-template-columns: minmax(0, 1fr); }
    /* Side by side only where each column has room for a chart at its
       smallest (28rem) inside a section's padding: decided by the space the
       grid has, not the window, which also holds the sidebar. */
    .mf-cols { display: grid; gap: 1.5rem; grid-template-columns: repeat(auto-fit, minmax(min(100%, 32rem), 1fr)); }
    .mf-heading { font-size: 0.875rem; font-weight: 600; color: var(--mf-ink); margin: 0 0 0.25rem; }
    .mf-sub { font-size: 0.8125rem; color: var(--mf-muted); margin: 0 0 0.75rem; }

    .mf-sr { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }

    .mf-list { width: 100%; border-collapse: collapse; font-size: 0.8125rem; }
    .mf-list th, .mf-list td { text-align: left; padding: 0.375rem 0.5rem; border-bottom: 1px solid var(--mf-grid); color: var(--mf-ink-2); }
    .mf-list th { color: var(--mf-muted); font-weight: 500; }
    .mf-list .mf-num { text-align: right; }

    @media (forced-colors: active) {
        .mf-line { stroke: CanvasText; }
        .mf-bar, .mf-seg, .mf-area, .mf-end, .mf-dot { fill: CanvasText; }
        .mf-swatch { background: CanvasText; forced-color-adjust: none; }
        .mf-hbar .mf-bar { background: CanvasText; forced-color-adjust: none; }
        .mf-hbar .mf-track { background: GrayText; forced-color-adjust: none; }
    }
</style>
