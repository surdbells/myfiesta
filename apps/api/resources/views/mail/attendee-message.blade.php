<x-mail::message>
@if ($important)
# Important: {{ $event->title }}
@else
# {{ $event->title }}
@endif

{{ $body }}

<x-mail::panel>
**{{ $localTime }}**
{{ $event->venue?->name ?? $event->city }}@if ($event->venue?->address_line), {{ $event->venue->address_line }}@endif
</x-mail::panel>

<x-mail::button :url="$url">
See the event
</x-mail::button>

{{ $organizer }}

<x-slot:subcopy>
@if ($important)
You are getting this because you have a ticket to this event, and it is
something you need to know before you travel. Messages like this one are sent
even if you have turned off event reminders.
@else
You are getting this because you have a ticket to this event.
[Stop event emails]({{ $unsubscribeUrl }}) — this will not affect your ticket
or your order emails.
@endif
</x-slot:subcopy>
</x-mail::message>
