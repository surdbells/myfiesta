<x-mail::message>
@if (count($listed) > 0)
# Dates of {{ $event->title }}

These dates of **{{ $event->title }}** reached the time set for them to go on sale:

@foreach ($listed as $date)
@if ($date['outcome'] === 'on_sale')
- **{{ $date['when'] }}** — on sale: {{ $date['link'] }}
@elseif ($date['outcome'] === 'in_review')
- **{{ $date['when'] }}** — went to myFiesta for review, because it is not exactly what was approved. It goes on sale once it is approved.
@else
- **{{ $date['when'] }}** — did not go on sale: {{ implode(' ', $date['reasons']) }} It is still a draft.
@endif
@endforeach

@if (collect($listed)->contains('outcome', 'in_review'))
Most reviews are done within {{ $wait }}, and we email you either way.

@endif
<x-mail::button :url="$url">
Open the event
</x-mail::button>
@elseif ($outcome === 'on_sale')
# {{ $event->title }} is on sale

**{{ $event->title }}** went on sale at the time you set. Its page is live and tickets are on sale now. Share this link:

<x-mail::panel>
{{ $link }}
</x-mail::panel>

<x-mail::button :url="$link">
Open the event page
</x-mail::button>
@elseif ($outcome === 'in_review')
# {{ $event->title }} went for review

At the time you set, **{{ $event->title }}** was not exactly what myFiesta last approved — something a buyer sees was changed or added since, or it had not been approved yet. So instead of going on sale, it came to us for review.

Most events are looked at within {{ $wait }}. If it is approved, it goes on sale straight away and we email you the link. If something needs changing, we send it back with the reason. While it waits it cannot be changed.

<x-mail::button :url="$url">
Open the event
</x-mail::button>
@else
# {{ $event->title }} did not go on sale

**{{ $event->title }}** was set to go on sale now, and could not be:

@foreach ($reasons as $reason)
- {{ $reason }}
@endforeach

It is still a draft, and nothing has been sent. Once that is put right, put it on sale from the event in the console, or set a new time.

<x-mail::button :url="$url">
Open the event
</x-mail::button>
@endif

Questions? Reply to this email and a person will answer.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
