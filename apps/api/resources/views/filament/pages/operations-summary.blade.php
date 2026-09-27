{{-- What is running and what is waiting, at a glance. Every count is read fresh on each visit. --}}
<div class="mf-stack">
    <x-filament::section
        heading="Background processes"
        description="When the scheduler and the queue worker were last seen running. Neither says anything when it stops — the emails just never go — so each writes the time as it runs, and one that has not for too long is late here and fails the readiness check."
    >
        <table class="mf-list">
            <caption class="mf-sr">When each background process was last seen running</caption>
            <thead>
                <tr>
                    <th scope="col">Process</th>
                    <th scope="col">Last seen</th>
                    <th scope="col">Late after</th>
                    <th scope="col">State</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($processes as $process)
                    <tr data-process="{{ $process['name'] }}">
                        <th scope="row">
                            {{ $process['label'] }}
                            <div class="mf-note" style="margin-top: 0">{{ $process['what'] }}</div>
                        </th>
                        <td @if ($process['last_seen_at']) title="{{ \Carbon\CarbonImmutable::parse($process['last_seen_at'])->utc()->format('j M, H:i:s') }} UTC" @endif>{{ $process['last_seen'] }}</td>
                        <td>{{ $process['late_after'] }}</td>
                        <td>
                            <x-filament::badge :color="$process['late'] ? 'danger' : 'success'" style="width: fit-content">{{ $process['state'] }}</x-filament::badge>
                            @if ($process['note'])
                                <div class="mf-note" style="margin-top: 0.25rem">{{ $process['note'] }}</div>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-filament::section>

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
