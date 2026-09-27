{{--
    The tickets from the sale to the door, and the night itself, in the order
    it happened. Every line is a record: the ticket history, the door's scans,
    the refunds, the night's completion record.
--}}
@php
    /** @var \App\Services\Disputes\CaseFile $case */
    $order = $case->order;
    $night = $case->completion;
@endphp

<h1>Delivery, access and entry — order {{ $order->reference }}</h1>
<p class="meta">
    {{ $case->eventTitle() }}@if ($case->eventWhen()), {{ $case->eventWhen() }}@endif@if ($case->eventWhere()), {{ $case->eventWhere() }}@endif.
    Bought by {{ $order->buyer_name }}.
</p>

<h2>The tickets</h2>
@if ($case->tickets->isEmpty())
    <p class="none">No ticket was issued for this order.</p>
@else
    <table>
        <tr><th>Ticket</th><th>Issued</th><th>Let in at the door</th><th>Now</th></tr>
        @foreach ($case->tickets as $ticket)
            @php $in = $case->admissions()->firstWhere('ticket_id', $ticket->id); @endphp
            <tr>
                <td>{{ $case->ticketLabel($ticket) }}</td>
                <td>{{ \App\Services\Disputes\CaseFile::at($ticket->created_at) }}</td>
                <td>{{ $in ? \App\Services\Disputes\CaseFile::at($in->scanned_at).' — by '.$case->scannedBy($in) : 'Not scanned in' }}</td>
                <td>{{ str_replace('_', ' ', $ticket->status) }}</td>
            </tr>
        @endforeach
    </table>
@endif

<h2>What happened, in order</h2>
<table>
    <tr><th>When (UTC)</th><th>What</th></tr>
    @foreach ($timeline as $entry)
        <tr>
            <td class="when">{{ \App\Services\Disputes\CaseFile::at($entry['at']) }}</td>
            <td>
                {{ $entry['what'] }}
                @if ($entry['detail'])
                    <br><span class="small">{{ $entry['detail'] }}</span>
                @endif
            </td>
        </tr>
    @endforeach
</table>

<h2>The night</h2>
@if ($night)
    <p>Written down once the door had closed, from the event as it was listed then and from every scan at its door. The record cannot be changed afterwards, by the organizer or by us.</p>
    <table class="facts">
        <tr><th>Event</th><td>{{ $night->title }}</td></tr>
        <tr><th>Listed for</th><td>{{ \App\Services\Disputes\CaseFile::at($night->starts_at) }}@if ($night->ends_at) to {{ \App\Services\Disputes\CaseFile::at($night->ends_at) }}@endif ({{ $night->timezone }})</td></tr>
        @if ($night->venue || $night->city)
            <tr><th>Venue</th><td>{{ collect([$night->venue, $night->city])->filter()->implode(', ') }}</td></tr>
        @endif
        <tr><th>Door open</th><td>{{ $night->door_opened_at ? \App\Services\Disputes\CaseFile::at($night->door_opened_at).' to '.\App\Services\Disputes\CaseFile::at($night->door_closed_at) : 'No scans at the door' }}</td></tr>
        <tr><th>People let in</th><td>{{ $night->people_admitted }}</td></tr>
        <tr><th>Tickets issued</th><td>{{ $night->tickets_issued }} ({{ $night->tickets_live }} still good for entry when it ended)</td></tr>
        <tr><th>Scans turned away</th><td>{{ $night->turned_away }} of {{ $night->scans }}</td></tr>
        <tr><th>Recorded</th><td>{{ \App\Services\Disputes\CaseFile::at($night->recorded_at) }}</td></tr>
    </table>
@elseif ($case->wasCancelled())
    <p>The night was cancelled{{ $case->event?->cancelled_at ? ' on '.\App\Services\Disputes\CaseFile::at($case->event->cancelled_at) : '' }}.</p>
@elseif ($case->hasHappened())
    <p class="none">The night has not been recorded as complete yet; it is written down half a day after its door closes.</p>
@else
    <p class="none">The night has not taken place yet.</p>
@endif
