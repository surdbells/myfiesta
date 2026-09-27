<x-filament-widgets::widget>
    <x-filament::section heading="Orders and tickets" :description="$description">
        <x-charts.bar
            title="Orders and tickets"
            :labels="$labels"
            :series="$series"
            empty="No orders in this period."
        />
    </x-filament::section>
</x-filament-widgets::widget>
