<x-mail::message>
# You're on the list

{{ $organizer }} has put you on the list for **{{ $event->title }}**.

**{{ $event->starts_at->timezone($event->timezone)->format('l j F Y, g:ia') }}**
{{ $event->venue?->name ?? $event->city }}

@if ($note)
<x-mail::panel>
{{ $note }}
</x-mail::panel>
@endif

<x-mail::panel>
@foreach ($tickets as $ticket)
`{{ $ticket->code }}`@if ($ticket->admits > 1) — admits {{ $ticket->admits }}@endif

@endforeach
</x-mail::panel>

Show this at the door. Nothing has been charged.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
