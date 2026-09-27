<x-mail::message>
# {{ $organization->name }} has been suspended

We have suspended **{{ $organization->name }}** on {{ config('app.name') }}.

@if ($reason)
<x-mail::panel>
{{ $reason }}
</x-mail::panel>
@endif

**What has stopped**

@if ($eventsOffSale > 0)
- {{ $eventsOffSale === 1 ? 'Your one event on sale has' : 'Your '.$eventsOffSale.' events on sale have' }} been taken off sale.
@endif
- Nothing can be sold: online, at the door, or handed back for resale.
- Events cannot be published while the suspension lasts.
- Payouts are paused. A payout you have already asked for is held, not rejected, and goes ahead once the suspension is lifted.

**What still works**

- Everybody who already has a ticket keeps it, and your door still lets them in.
- Refunds can still be made, and you can still write to your ticket holders.
- You can sign in to the console and read everything in it.

If you think this is a mistake, or want to know what to do next, reply to this email and a person will look at it.

<x-mail::button :url="$url">
Open the console
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
