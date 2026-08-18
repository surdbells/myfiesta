<x-mail::message>
# You're going to {{ $event->title }}

{{ $order->buyer_name }}, your {{ trans_choice('ticket|tickets', $tickets->count()) }}
{{ $tickets->count() === 1 ? 'is' : 'are' }} confirmed.

**{{ $event->starts_at->timezone($event->timezone)->format('l j F Y, g:ia') }}**
{{ $event->venue?->name ?? $event->city }}

<x-mail::panel>
@foreach ($tickets as $ticket)
**{{ $ticket->ticketType->name }}** — `{{ $ticket->code }}`
@endforeach
</x-mail::panel>

<x-mail::button :url="$url">
View your tickets
</x-mail::button>

Show the QR code at the door. This link works for 90 days and is unique to you —
treat it like the tickets themselves.

Order **{{ $order->reference }}**

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
