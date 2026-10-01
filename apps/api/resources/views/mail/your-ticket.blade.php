<x-mail::message>
# Your ticket for {{ $event->title }}

@if ($sender)
{{ $sender }} sent you a ticket for **{{ $event->title }}**{{ $ticket->holder_name ? ', in the name of '.$ticket->holder_name : '' }}.
@elseif ($reissued)
A ticket for **{{ $event->title }}** has been moved to this address{{ $ticket->holder_name ? ', in the name of '.$ticket->holder_name : '' }}.
@else
Here is your ticket for **{{ $event->title }}**, sent again as you asked.
@endif

**{{ $event->starts_at->timezone($event->timezone)->format('l j F Y, g:ia') }}**
{{ $event->venue?->name ?? $event->city }}

@if ($stillTheirs)
<x-mail::panel>
**{{ $ticket->ticketType?->name ?? 'Ticket' }}** — `{{ $ticket->code }}`@if ($ticket->admits > 1) — admits {{ $ticket->admits }}@endif

</x-mail::panel>

@if ($link)
<x-mail::button :url="$link">
Open your ticket
</x-mail::button>

The link opens this ticket with the QR the door scans. It works for as long as the ticket is yours.
@endif

Show this code at the door. Treat it like the ticket itself: anybody holding it can use it once.
@if ($sender)
It is a new code. The one {{ $sender }} had no longer gets anybody in.
@elseif ($reissued && $newCode)
This is a new code. Any earlier email for this ticket no longer gets anybody in.
@endif
@else
This ticket has since been sent on to somebody else, so it is no longer yours and there is no code here.
@endif

@if ($organizer)
{{ $organizer }} is running this event. If something looks wrong, reply to this email and a person at {{ config('app.name') }} will answer.
@endif

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
