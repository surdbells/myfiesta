<x-mail::message>
# Tickets are available for {{ $event->title }}

@if ($name)
Hi {{ $name }} — you
@else
You
@endif
asked to hear if tickets came up. Some have.

**{{ $localTime }}**
{{ $event->venue?->name ?? $event->city }}

@if ($note)
> {{ $note }}
@endif

They go to whoever buys first, and this email does not hold one for you.

<x-mail::button :url="$url">
Get tickets
</x-mail::button>

{{ $event->organization->name }}

<x-slot:subcopy>
You are getting this because you joined the waitlist for this event.
[Leave the waitlist]({{ $leaveUrl }}).
</x-slot:subcopy>
</x-mail::message>
