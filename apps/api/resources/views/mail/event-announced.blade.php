<x-mail::message>
# {{ $organizer->name }} announced a night

## {{ $event->title }}

**{{ $localTime }}**
@if ($where)
<br>{{ $where }}
@endif

@if ($blurb)
{{ $blurb }}
@endif

<x-mail::button :url="$url">
Have a look
</x-mail::button>

<x-slot:subcopy>
You are getting this because you follow {{ $organizer->name }}.
[Stop following them]({{ $stopUrl }}) and this is the last one.
</x-slot:subcopy>
</x-mail::message>
