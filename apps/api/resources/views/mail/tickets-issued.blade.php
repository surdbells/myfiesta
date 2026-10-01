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

Show the QR code at the door. This link is unique to you — treat it like the
tickets themselves. The date is attached as a calendar file.

@php
    // The buyer's friend's link, when the night offers one: made here if the
    // queued listener has not got to it yet (ShareLinks::forOrder). A link
    // that cannot be made leaves the block out; it never stops the tickets.
    $shareLinks = app(\App\Services\Sharing\ShareLinks::class);
    $share = $order->exists ? rescue(fn () => $shareLinks->forOrder($order), null) : null;
    $sharePercent = $share ? $shareLinks::percent((int) $shareLinks->bpsFor($event)) : null;
@endphp
@if ($share)
## Bring a friend, and you both save

Send a friend this link. They get {{ $sharePercent }}% off their tickets, and once
they have paid you get {{ $sharePercent }}% off your next tickets from {{ $event->organization?->name }}.
It is a different link from the one to your tickets, so it is safe to pass on.

<x-mail::panel>
{{ $shareLinks->url($share, $event) }}
</x-mail::panel>
@endif

@if ($receipt)
## Receipt

Order **{{ $receipt->reference }}**, {{ $receipt->issuedAt->timezone($event->timezone)->format('j F Y') }}

<x-mail::table>
| | Qty | Amount |
| :-- | :-: | --: |
@foreach ($receipt->lines as $line)
| {{ str_replace('|', '/', $line['name']) }} | {{ $line['quantity'] }} | {{ $line['amount']->format() }} |
@endforeach
@if ($receipt->discount->amount > 0)
| Discount | | {{ \App\Support\Money::of(-$receipt->discount->amount, $receipt->currency)->format() }} |
@endif
@foreach ($receipt->taxesOn('tickets') as $tax)
| {{ $tax->name }} {{ $tax->percent() }}%{{ $tax->inclusive ? ', included' : '' }} | | {{ $tax->amount->format() }} |
@endforeach
@if ($receipt->serviceCharge->amount > 0)
| Service charge | | {{ $receipt->serviceCharge->format() }} |
@endif
@foreach ($receipt->taxesOn('service_charge') as $tax)
| {{ $tax->name }} {{ $tax->percent() }}% on the service charge{{ $tax->inclusive ? ', included' : '' }} | | {{ $tax->amount->format() }} |
@endforeach
| **Total** | | **{{ $receipt->total->format() }}** |
</x-mail::table>

@php
    // One line per party: who, where, and the numbers its taxes are filed under.
    $party = fn (array $who) => collect([$who['address'] ? str_replace(["\r\n", "\n"], ', ', $who['address']) : null])
        ->merge(array_map(fn (array $r) => $r['label'].' '.$r['number'], $who['registrations']))
        ->filter()
        ->implode(' · ');
@endphp
@if ($receipt->sellerOfRecord->value === 'platform')
Sold by **{{ $receipt->seller['name'] }}**{{ $receipt->organizer ? ' on behalf of '.$receipt->organizer : '' }}.
@else
Tickets sold by **{{ $receipt->seller['name'] }}**.
@endif
@if ($party($receipt->seller) !== '')
{{ $party($receipt->seller) }}
@endif
@if ($receipt->service)

Service charge by **{{ $receipt->service['name'] }}**.
@if ($party($receipt->service) !== '')
{{ $party($receipt->service) }}
@endif
@endif
@else
Order **{{ $order->reference }}**
@endif

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
