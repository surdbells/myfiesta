<x-mail::message>
# {{ $event->title }} sold out while you were paying

{{ $order->buyer_name }}, the last places went to somebody else in the minutes between you starting to pay
and your payment reaching us. So we have not issued any tickets on this order, and we have refunded every
penny of it.

<x-mail::panel>
**{{ $amount }}** is on its way back to the card or account you paid with.
</x-mail::panel>

There is nothing you need to do. Depending on your bank, it can take a few working days to show.

If it has not arrived after ten working days, reply to this email and a person will look into it.

Order **{{ $order->reference }}**

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
