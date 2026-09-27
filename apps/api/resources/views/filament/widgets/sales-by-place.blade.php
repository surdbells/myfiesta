<x-filament-widgets::widget>
    <x-filament::section heading="Where the nights were" description="Gross sales by the city and country of the event.">
        <x-charts.hbar
            title="Gross sales by city"
            :items="$cities"
            format="money"
            :currency="$currency"
            :hue="3"
            label-heading="City"
            value-heading="Gross sales"
            empty="No sales in this period."
        />

        @if ($countries !== [])
            <table class="mf-list" style="margin-top: 0.75rem">
                <caption class="mf-sr">Gross sales by country</caption>
                <thead>
                    <tr>
                        <th scope="col">Country</th>
                        <th scope="col" class="mf-num">Orders</th>
                        <th scope="col" class="mf-num">Gross</th>
                        <th scope="col" class="mf-num">Share</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($countries as $country)
                        <tr>
                            <th scope="row">{{ $country['name'] }}</th>
                            <td class="mf-num">{{ number_format($country['orders']) }}</td>
                            <td class="mf-num">{{ $country['gross'] }}</td>
                            <td class="mf-num">{{ $country['share'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
