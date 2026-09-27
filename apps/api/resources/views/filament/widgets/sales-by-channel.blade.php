@php($F = \App\Services\Analytics\Charts\Format::class)
<x-filament-widgets::widget>
    <x-filament::section heading="Online and at the door" description="Gross sales by where the order was taken.">
        <x-charts.donut
            title="Sales by channel"
            :segments="$segments"
            format="money"
            :currency="$currency"
            center-label="Gross"
            empty="No sales in this period."
        />

        <table class="mf-list" style="margin-top: 0.75rem">
            <caption class="mf-sr">Orders, tickets and gross sales by channel</caption>
            <thead>
                <tr>
                    <th scope="col">Channel</th>
                    <th scope="col" class="mf-num">Orders</th>
                    <th scope="col" class="mf-num">Tickets</th>
                    <th scope="col" class="mf-num">Gross</th>
                </tr>
            </thead>
            <tbody>
                @foreach (['online' => 'Online', 'door' => 'At the door'] as $key => $name)
                    <tr>
                        <th scope="row">{{ $name }}</th>
                        <td class="mf-num">{{ number_format($channels[$key]['orders']) }}</td>
                        <td class="mf-num">{{ number_format($channels[$key]['tickets']) }}</td>
                        <td class="mf-num">{{ $F::money($channels[$key]['gross'], $currency) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-filament::section>
</x-filament-widgets::widget>
