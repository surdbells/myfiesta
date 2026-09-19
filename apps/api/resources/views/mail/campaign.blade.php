<x-mail::message>
{{ $body }}

@if ($event)
<x-mail::panel>
**{{ $event->title }}**<br>
{{ $localTime }}@if ($where)<br>{{ $where }}@endif
</x-mail::panel>

<x-mail::button :url="$url">
Get tickets
</x-mail::button>
@endif

{{ $organizer }}

<x-slot:subcopy>
{{ $why }}
[Stop emails like this]({{ $unsubscribeUrl }}) — from {{ $organizer }} and every other organizer. Reminders about tickets you hold still arrive.
</x-slot:subcopy>
</x-mail::message>
