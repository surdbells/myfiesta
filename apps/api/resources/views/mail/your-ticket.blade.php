<x-mail::message>
# Your ticket for {{ $event->title }}

@if ($reissued)
A ticket for **{{ $event->title }}** has been moved to this address{{ $ticket->holder_name ? ', in the name of '.$ticket->holder_name : '' }}.
@else
Here is your ticket for **{{ $event->title }}**, sent again as you asked.
@endif

**{{ $event->starts_at->timezone($event->timezone)->format('l j F Y, g:ia') }}**
{{ $event->venue?->name ?? $event->city }}

<x-mail::panel>
**{{ $ticket->ticketType?->name ?? 'Ticket' }}** — `{{ $ticket->code }}`@if ($ticket->admits > 1) — admits {{ $ticket->admits }}@endif

</x-mail::panel>

Show this code at the door. Treat it like the ticket itself: anybody holding it can use it once.
@if ($reissued && $newCode)
This is a new code. Any earlier email for this ticket no longer gets anybody in.
@endif

@if ($organizer)
{{ $organizer }} is running this event. If something looks wrong, reply to this email and a person at {{ config('app.name') }} will answer.
@endif

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
