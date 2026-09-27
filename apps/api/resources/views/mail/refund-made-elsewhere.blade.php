<x-mail::message>
# {{ $amount }} was refunded in {{ $processor }}

Somebody refunded {{ $amount }} on order **{{ $order->reference }}** for {{ $event->title }}, bought by
{{ $order->buyer_name }}. It was done in the {{ $processor }} dashboard rather than here, so we have recorded
it for you: your balance no longer counts that money, and the order shows the refund.

@if ($whole)
That was everything left on the order, so its tickets have been cancelled and will not open the door.
@else
That was part of the order, and every ticket on it still works: {{ $processor }} does not tell us which tickets
the money was for, and we would rather not guess and turn away somebody who paid.

<x-mail::panel>
If it was for particular tickets, reply to this email and say which, and we will cancel them. Please do not
refund them here as well: that would send the money a second time.
</x-mail::panel>
@endif

<x-mail::button :url="$url">
Open the orders
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
