{{-- One event's report, over its whole life, in its own currency. --}}
@php($F = \App\Services\Analytics\Charts\Format::class)
<div class="mf-stack">
    <section aria-labelledby="event-report-heading">
        <h2 id="event-report-heading" class="mf-heading" style="font-size: 1rem">
            @if ($eventUrl)
                <a href="{{ $eventUrl }}" style="text-decoration: underline">{{ $event->title }}</a>
            @else
                {{ $event->title }}
            @endif
            @if ($event->trashed())
                <span class="mf-note">(removed)</span>
            @endif
        </h2>
        <p class="mf-sub">
            {{ $event->organization?->name ?? 'Unknown organizer' }} · {{ $starts }} · {{ $event->city }} · {{ ucfirst($event->status) }} · sells in {{ $currency }}
        </p>
        <div class="mf-kpis">
            @foreach ($tiles as $tile)
                <x-charts.stat :label="$tile['label']" :value="$tile['value']" :hint="$tile['hint'] ?? null" />
            @endforeach
        </div>
    </section>

    <div class="mf-cols">
        <x-filament::section heading="Sales pace" description="Tickets sold so far, counted back from the night. Sales made after the doors opened land on the night itself.">
            <x-charts.line
                title="Sales pace: cumulative tickets by days before the event"
                :labels="$pace['labels']"
                :series="$pace['series']"
                area
                x-label="Days before"
                empty="No tickets sold yet."
            />
        </x-filament::section>

        <x-filament::section heading="Where the money went" description="Of everything buyers paid for this event's orders.">
            <x-charts.donut
                title="Revenue split for {{ $event->title }}"
                :segments="$split"
                format="money"
                :currency="$currency"
                center-label="Gross"
                empty="No sales yet."
            />
        </x-filament::section>

        <x-filament::section heading="Tickets by type" description="Tickets sold in each tier against what the tier allows. A tier taken off sale still shows if it sold anything.">
            <x-charts.hbar
                title="Tickets sold by ticket type"
                :items="$types"
                :hue="2"
                label-heading="Ticket type"
                value-heading="Sold / available"
                empty="No tickets sold yet."
            />
        </x-filament::section>

        <x-filament::section heading="Revenue by type" description="Ticket revenue per tier after discounts, before tax and service charge.">
            <x-charts.hbar
                title="Ticket revenue by ticket type"
                :items="$typeRevenue"
                format="money"
                :currency="$currency"
                label-heading="Ticket type"
                value-heading="Revenue"
                empty="No ticket revenue yet."
            />
        </x-filament::section>

        <x-filament::section heading="Check-ins over the night" description="People through the door every 15 minutes, on the venue's clock. A group scanned once counts every person it admitted.">
            <x-charts.bar
                title="People checked in per 15 minutes"
                :labels="$checkIns['labels']"
                :series="[['name' => 'People admitted', 'values' => $checkIns['people'], 'slot' => 4]]"
                x-label="Time"
                empty="Nobody has been checked in."
            />
            @if ($checkIns['truncated'])
                <p class="mf-note">Only the first 24 hours of scanning are drawn.</p>
            @endif
        </x-filament::section>

        <x-filament::section heading="Online and at the door" description="Gross sales by where the order was taken.">
            <x-charts.donut
                title="Sales by channel for {{ $event->title }}"
                :segments="$channelSegments"
                format="money"
                :currency="$currency"
                center-label="Gross"
                empty="No sales yet."
            />
            <table class="mf-list" style="margin-top: 0.75rem">
                <caption class="mf-sr">Orders, tickets and gross by channel</caption>
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

        <x-filament::section heading="Discount codes used" description="Codes and promoter links typed at checkout, by orders. Ticket codes are never listed.">
            <x-charts.hbar
                title="Orders by discount code"
                :items="$codes"
                :hue="5"
                label-heading="Code"
                value-heading="Orders"
                empty="No order used a code."
            />
        </x-filament::section>

        <x-filament::section heading="Latest refunds" description="Newest first, failed ones included.">
            @if ($refunds === [])
                <div class="mf-empty" role="note">Nothing has been refunded.</div>
            @else
                <table class="mf-list">
                    <caption class="mf-sr">Latest refunds for this event</caption>
                    <thead>
                        <tr>
                            <th scope="col">Order</th>
                            <th scope="col">When</th>
                            <th scope="col">Reason</th>
                            <th scope="col">Status</th>
                            <th scope="col" class="mf-num">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($refunds as $refund)
                            <tr>
                                <th scope="row">{{ $refund['reference'] }}</th>
                                <td>{{ $refund['when'] }}</td>
                                <td>{{ $refund['reason'] }}</td>
                                <td>{{ $refund['status'] }}</td>
                                <td class="mf-num">{{ $refund['amount'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-filament::section>
    </div>
</div>
