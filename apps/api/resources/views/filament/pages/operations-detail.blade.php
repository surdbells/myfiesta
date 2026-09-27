{{-- Webhook failures by endpoint, and the schedule the background jobs run to. --}}
<div class="mf-cols">
    <x-filament::section
        heading="Webhook failures by endpoint"
        description="Deliveries that gave up, by the organizer's receiving endpoint. Only the address's host is shown: the rest of it is where people put tokens."
    >
        @if ($endpoints === [])
            <div class="mf-empty" role="note">No webhook delivery has failed.</div>
        @else
            <x-charts.hbar
                title="Failed webhook deliveries by endpoint"
                :items="$endpoints"
                :hue="5"
                label-heading="Organizer"
                value-heading="Failed deliveries"
            />
            <table class="mf-list" style="margin-top: 0.75rem">
                <caption class="mf-sr">Failing webhook endpoints</caption>
                <thead>
                    <tr>
                        <th scope="col">Organizer and address</th>
                        <th scope="col" class="mf-num">Failed</th>
                        <th scope="col" class="mf-num">In a row</th>
                        <th scope="col">Last failure</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($webhooks['endpoints'] as $endpoint)
                        <tr>
                            <th scope="row">
                                {{ $endpoint['organization'] }}
                                <div class="mf-note" style="margin-top: 0; overflow-wrap: anywhere">{{ $endpoint['url'] }} · endpoint {{ $endpoint['ref'] }}{{ $endpoint['disabled'] ? ' · switched off' : '' }}</div>
                            </th>
                            <td class="mf-num">{{ number_format($endpoint['failures']) }}</td>
                            <td class="mf-num">{{ number_format($endpoint['consecutive_failures']) }}</td>
                            <td>{{ \Carbon\CarbonImmutable::parse($endpoint['last_failed_at'], 'UTC')->diffForHumans() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-filament::section>

    <x-filament::section
        heading="Scheduled jobs"
        description="What the scheduler is set to run, and when each is next due (UTC). When each last ran is not recorded anywhere, so it is not shown."
    >
        @if ($schedule['error'])
            <div class="mf-empty" role="note">{{ $schedule['error'] }}</div>
        @elseif ($schedule['tasks'] === [])
            <div class="mf-empty" role="note">Nothing is scheduled.</div>
        @else
            <table class="mf-list">
                <caption class="mf-sr">Scheduled jobs</caption>
                <thead>
                    <tr>
                        <th scope="col">Command</th>
                        <th scope="col">Schedule</th>
                        <th scope="col">Next due</th>
                        <th scope="col">Last run</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($schedule['tasks'] as $task)
                        <tr>
                            <th scope="row"><code>{{ $task['command'] }}</code></th>
                            <td><code>{{ $task['expression'] }}</code></td>
                            <td>{{ $task['next_due'] ? \Carbon\CarbonImmutable::parse($task['next_due'])->utc()->format('j M, H:i') : '—' }}</td>
                            <td>Not recorded</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-filament::section>
</div>
