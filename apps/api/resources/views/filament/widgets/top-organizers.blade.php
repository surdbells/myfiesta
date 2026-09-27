<x-filament-widgets::widget>
    <x-filament::section heading="Top organizers" description="By gross sales in the period. Open one for its full report.">
        <x-charts.hbar
            title="Top organizers by gross sales"
            :items="$items"
            format="money"
            :currency="$currency"
            label-heading="Organizer"
            value-heading="Gross sales"
            empty="No organizer sold anything in this period."
        />
    </x-filament::section>
</x-filament-widgets::widget>
