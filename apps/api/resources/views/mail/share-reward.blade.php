<x-mail::message>
# A friend used your link

Somebody you sent your link for **{{ $event->title }}** has bought their tickets
with it. They saved {{ $percent }}%, and so do you: here is {{ $percent }}% off
your next tickets from {{ $organizer }}.

<x-mail::panel>
**{{ $code }}**
</x-mail::panel>

Type it in the promo code box at checkout, for any of {{ $organizer }}'s nights.
@if ($expires)
It works once, until {{ $expires }}.
@else
It works once.
@endif

<x-mail::button :url="$url">
See what {{ $organizer }} has on
</x-mail::button>

@if ($left > 0)
Your link can earn you {{ $left }} more {{ $left === 1 ? 'code' : 'codes' }} like this one.
@else
That was the last code your link can earn for this night. Friends can still save with it.
@endif

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
