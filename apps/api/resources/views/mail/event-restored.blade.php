<x-mail::message>
@if ($waitsForSuspension)
# The takedown on {{ $event->title }} is lifted

We have lifted the takedown on **{{ $event->title }}**. Your organization's sales are suspended at the moment, so it stays off sale for now. It goes back on sale by itself when the suspension is lifted, as long as it has not started by then — there is nothing you need to do.
@elseif ($event->status === 'published')
# {{ $event->title }} is back on sale

We have lifted the takedown on **{{ $event->title }}**. Its page is visible again and tickets are on sale.
@else
# You can publish {{ $event->title }} again

We have lifted the takedown on **{{ $event->title }}**. It is a draft for now — publish it from the console when you are ready.
@endif

<x-mail::button :url="$url">
Open the event
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
