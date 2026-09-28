<x-mail::message>
# A new event is waiting for review

**{{ $title }}** from **{{ $organizer }}** is waiting to go on sale.

- Starts {{ $starts }}
@if ($where !== '')
- In {{ $where }}
@endif
@if ($again)
- It has been reviewed before. The review page shows what changed since.
@endif

Approve it to put it on sale, or send it back to the organizer with a reason they can act on.

<x-mail::button :url="$link">
Review it
</x-mail::button>

{{ config('app.name') }}
</x-mail::message>
