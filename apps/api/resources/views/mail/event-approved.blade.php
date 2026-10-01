<x-mail::message>
@if ($onSaleAt)
# {{ $event->title }} is approved

We have approved **{{ $event->title }}**. It goes on sale by itself on **{{ $onSaleAt }}**, the time you set — there is nothing you need to do. Until then it stays a draft, and its link does not open.

@if ($waitsForSuspension)
Your organization's sales are suspended at the moment. If they still are at that time, it goes on sale when the suspension is lifted, as long as it has not started by then.
@endif

You can change the time, or put it on sale now, from the event in the console. If you change anything a buyer sees before then, it comes back to us for review when the time comes, and goes on sale once it is approved.

<x-mail::button :url="$url">
Open the event
</x-mail::button>
@elseif ($waitsForSuspension)
# {{ $event->title }} is approved

We have approved **{{ $event->title }}**. Your organization's sales are suspended at the moment, so it is not on sale yet. It goes on sale by itself when the suspension is lifted, as long as it has not started by then — there is nothing you need to do.

<x-mail::button :url="$url">
Open the event
</x-mail::button>
@else
# {{ $event->title }} is on sale

We have approved **{{ $event->title }}**. Its page is live and tickets are on sale now. Share this link:

<x-mail::panel>
{{ $link }}
</x-mail::panel>

<x-mail::button :url="$link">
Open the event page
</x-mail::button>

You can still change the event while it is on sale. Taking it off sale and putting it back does not need another review, as long as nothing a buyer sees has changed since this approval.
@endif

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
