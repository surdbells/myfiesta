<x-filament-widgets::widget>
    <x-filament::section heading="Top events" description="By gross sales in the period, whenever the night itself is. Open one for its full report.">
        <x-charts.hbar
            title="Top events by gross sales"
            :items="$items"
            format="money"
            :currency="$currency"
            :hue="2"
            label-heading="Event"
            value-heading="Gross sales"
            empty="No event sold anything in this period."
        />
    </x-filament::section>
</x-filament-widgets::widget>
