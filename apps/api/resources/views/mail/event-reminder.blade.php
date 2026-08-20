<x-mail::message>
# {{ $event->title }} is {{ $when }}

**{{ $localTime }}**
{{ $event->venue?->name ?? $event->city }}@if ($event->venue?->address_line), {{ $event->venue->address_line }}@endif

@if ($event->min_age)
{{ $event->min_age }}+ · bring photo ID{{ $event->id_required ? '' : ' if you have it' }}.
@endif

Your ticket is in the email you got when you bought it — search your inbox for
**{{ $event->title }}**. Have the code ready at the door.

<x-mail::button :url="$url">
See the event
</x-mail::button>

See you there,<br>
{{ $event->organization->name }}

<x-slot:subcopy>
You are getting this because you have a ticket to this event.
[Stop event reminders]({{ $unsubscribeUrl }}) — this will not affect your ticket
or your order emails.
</x-slot:subcopy>
</x-mail::message>
