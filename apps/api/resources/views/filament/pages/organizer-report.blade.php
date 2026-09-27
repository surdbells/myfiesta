{{-- One organizer's report, for the market and period picked above it. --}}
<div class="mf-stack">
    <section aria-labelledby="organizer-report-heading">
        <h2 id="organizer-report-heading" class="mf-heading" style="font-size: 1rem">
            {{ $organization->name }}
            @if ($organization->trashed())
                <span class="mf-note">(closed)</span>
            @elseif (! $organization->verified_at)
                <span class="mf-note">(not verified)</span>
            @endif
        </h2>
        <p class="mf-sub">Key figures in {{ $currency }}, {{ $comparison }} where there is something to compare.</p>
        <div class="mf-kpis">
            @foreach ($tiles as $tile)
                <x-charts.stat
                    :label="$tile['label']"
                    :value="$tile['value']"
                    :change="$tile['change'] ?? null"
                    :up-is-good="$tile['upIsGood'] ?? true"
                    :comparison="array_key_exists('change', $tile) ? $comparison : null"
                    :trend="$tile['trend'] ?? null"
                    :hint="$tile['hint'] ?? null"
                />
            @endforeach
        </div>
    </section>

    <div class="mf-cols">
        <x-filament::section heading="Sales over time" description="What buyers paid per {{ $granularity }}, and the organizer's share of it.">
            <x-charts.line
                title="Sales over time for {{ $organization->name }}"
                :labels="$labels"
                :series="$sales"
                format="money"
                :currency="$currency"
                empty="No sales in this period."
            />
        </x-filament::section>

        {{-- Settlements are for the staff the settlement screen admits; what was earned is the line above. --}}
        @if ($seesSettlements)
            <x-filament::section heading="Earned and paid out" description="The organizer's share of each {{ $granularity }}'s sales beside the settlements recorded in it.">
                <x-charts.bar
                    title="Earned against paid out for {{ $organization->name }}"
                    :labels="$labels"
                    :series="$payouts"
                    format="money"
                    :currency="$currency"
                    empty="Nothing earned or paid out in this period."
                />
            </x-filament::section>
        @endif

        <x-filament::section heading="Events ranked" description="Their nights by gross sales made in the period.">
            <x-charts.hbar
                title="Events ranked by gross sales"
                :items="$events"
                format="money"
                :currency="$currency"
                label-heading="Event"
                value-heading="Gross sales"
                empty="No event of theirs sold anything in this period."
            />
        </x-filament::section>

        <x-filament::section heading="Tickets sold against capacity" description="Nights held in the period: paid tickets that still admit somebody, against what the tiers allow.">
            <x-charts.hbar
                title="Tickets sold against capacity"
                :items="$fill"
                :hue="2"
                label-heading="Event"
                value-heading="Sold / capacity"
                empty="No ticketed nights of theirs in this period."
            />
        </x-filament::section>

        <x-filament::section heading="Where their money went" description="Of everything buyers paid for their orders in the period.">
            <x-charts.donut
                title="Revenue split for {{ $organization->name }}"
                :segments="$split"
                format="money"
                :currency="$currency"
                center-label="Gross"
                empty="No sales in this period."
            />
        </x-filament::section>
    </div>
</div>
