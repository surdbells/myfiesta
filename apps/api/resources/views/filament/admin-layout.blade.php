{{--
    Where Filament's own layout runs out of room on a laptop.

    A record page's header puts the title and every action in one row that
    does not wrap: at about 1,090px the title was squeezed to a word a line
    and "Lift suspension" or "Check with Stripe" ran off the right of the page.
    Here the title keeps a sensible width and the actions go below it when
    they do not fit beside it, wrapping among themselves as they already do.

    A table wider than the page, and a row of tabs, scroll sideways inside
    their own box, and draw no scrollbar while they do — like the charts
    (.mf-scroll) and the console and the site. The column cut off at the edge
    says there is more; a trackpad, a finger or Shift with the wheel reach
    it, and a keyboard does by moving along the links in a table's rows and
    the tabs themselves. Neither box scrolls down, so hiding the bar in both
    directions, which is all an engine but Chromium can do, hides no bar that
    says how long anything is.
--}}
<style data-mf-admin-layout>
    @media (min-width: 40rem) {
        .fi-header { flex-wrap: wrap; row-gap: 1rem; }
        .fi-header > :first-child { flex: 1 1 16rem; min-width: 0; }
        .fi-header > .fi-header-actions-ctn { min-width: 0; max-width: 100%; }
    }

    .fi-ta-content-ctn, .fi-tabs { scrollbar-width: none; }
    .fi-ta-content-ctn::-webkit-scrollbar, .fi-tabs::-webkit-scrollbar { display: none; }
</style>
