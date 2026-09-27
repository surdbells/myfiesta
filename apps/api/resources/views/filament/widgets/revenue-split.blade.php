<x-filament-widgets::widget>
    <x-filament::section
        heading="Where the money went"
        description="Of everything buyers paid for orders in this period. Refunds are those made against these orders, whenever they were made, so the parts add up to the gross."
    >
        <div class="mf-cols">
            <div>
                <h3 class="mf-heading">The period as a whole</h3>
                <x-charts.donut
                    title="Revenue split for the period"
                    :segments="$segments"
                    format="money"
                    :currency="$currency"
                    center-label="Gross"
                    empty="No sales in this period."
                />
                <p class="mf-note">Processor fees of {{ $fees }} were paid out of the platform's service charge.</p>
            </div>
            <div>
                <h3 class="mf-heading">Per {{ $granularity }}</h3>
                <x-charts.bar
                    title="Revenue kept per {{ $granularity }}"
                    :labels="$labels"
                    :series="$series"
                    stacked
                    format="money"
                    :currency="$currency"
                    empty="No sales in this period."
                />
                <p class="mf-note">What was kept of each {{ $granularity }}'s sales: refunds come off the {{ $granularity }} the order was sold, so the columns add up to the ring's first three parts.</p>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
