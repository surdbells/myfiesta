{{--
    Where Filament's own layout runs out of room on a laptop.

    A record page's header puts the title and every action in one row that
    does not wrap: at about 1,090px the title was squeezed to a word a line
    and "Lift suspension" or "Check with Stripe" ran off the right of the page.
    Here the title keeps a sensible width and the actions go below it when
    they do not fit beside it, wrapping among themselves as they already do.
--}}
<style data-mf-admin-layout>
    @media (min-width: 40rem) {
        .fi-header { flex-wrap: wrap; row-gap: 1rem; }
        .fi-header > :first-child { flex: 1 1 16rem; min-width: 0; }
        .fi-header > .fi-header-actions-ctn { min-width: 0; max-width: 100%; }
    }
</style>
