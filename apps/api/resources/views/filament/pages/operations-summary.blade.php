{{-- What is waiting, at a glance. Every count is read fresh on each visit. --}}
<div class="mf-stack">
    <section aria-label="What is waiting">
        <div class="mf-kpis">
            @foreach ($tiles as $tile)
                <x-charts.stat :label="$tile['label']" :value="$tile['value']" :hint="$tile['hint'] ?? null" :href="$tile['href'] ?? null" />
            @endforeach
        </div>
    </section>

    @if ($queues !== [])
        <x-filament::section heading="Failed jobs by queue" description="Which queues the failures below are on.">
            <x-charts.hbar
                title="Failed jobs by queue"
                :items="$queues"
                :hue="5"
                label-heading="Queue"
                value-heading="Failed jobs"
                empty="No failed jobs."
            />
        </x-filament::section>
    @endif
</div>
