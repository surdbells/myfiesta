<x-filament-widgets::widget>
    <x-filament::section heading="Sales over time" :description="$description">
        <x-charts.line
            title="Gross sales"
            :labels="$labels"
            :series="[['name' => 'Gross sales', 'values' => $gross, 'slot' => 1]]"
            format="money"
            :currency="$currency"
            area
            empty="No sales in this period."
        />
    </x-filament::section>
</x-filament-widgets::widget>
