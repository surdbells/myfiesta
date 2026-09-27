<x-filament-widgets::widget>
    <x-filament::section
        heading="Check-in rate"
        description="Nights held in the period: people who arrived against people holding a ticket, comps included. A table for five is five people."
    >
        @if ($items === [])
            <div class="mf-empty" role="note">No ticketed nights were held in this period.</div>
        @else
            <p class="mf-sub">
                <span class="mf-stat-value" style="font-size: 1.125rem">{{ $rate }}</span>
                — {{ $arrived }} of {{ $people }} people arrived.
            </p>
            <x-charts.hbar
                title="Arrivals against tickets held, latest nights first"
                :items="$items"
                :hue="4"
                label-heading="Event"
                value-heading="Arrived / holding tickets"
                empty="Nobody has arrived at a night in this period yet."
            />
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
