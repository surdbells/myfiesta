<x-mail::message>
# {{ $event->title }} is off sale

We have taken **{{ $event->title }}** off sale on {{ config('app.name') }}. Its page is hidden and nobody can buy a ticket for it until this is resolved.

<x-mail::panel>
{{ $reason }}
</x-mail::panel>

Nothing has been deleted. Tickets already sold still work, and your orders and sales figures are unchanged. You cannot publish the event again yourself while it is taken down.

If you think this is a mistake, or once you have fixed what is described above, reply to this email and a person will look at it.

<x-mail::button :url="$url">
Open the event
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
