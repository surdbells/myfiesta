<x-mail::message>
@switch($why)
@case('cancelled')
# {{ $event->title }} has been cancelled

{{ $order->buyer_name }}, the organizer called it off before your payment reached us. So we have not issued any
tickets on this order, and we have refunded every penny of it.
@break
@case('ended')
# {{ $event->title }} has already happened

{{ $order->buyer_name }}, your payment reached us after the event had ended. So we have not issued any tickets on
this order, and we have refunded every penny of it.
@break
@case('taken_down')
# {{ $event->title }} is no longer on sale

{{ $order->buyer_name }}, it was taken off sale before your payment reached us. So we have not issued any tickets
on this order, and we have refunded every penny of it.
@break
@case('suspended')
# {{ $event->title }} is no longer on sale

{{ $order->buyer_name }}, its organizer was no longer selling tickets on myFiesta when your payment reached us. So
we have not issued any tickets on this order, and we have refunded every penny of it.
@break
@default
# {{ $event->title }} sold out while you were paying

{{ $order->buyer_name }}, the last places went to somebody else in the minutes between you starting to pay
and your payment reaching us. So we have not issued any tickets on this order, and we have refunded every
penny of it.
@endswitch

<x-mail::panel>
**{{ $amount }}** is on its way back to the card or account you paid with.
</x-mail::panel>

There is nothing you need to do. Depending on your bank, it can take a few working days to show.

If it has not arrived after ten working days, reply to this email and a person will look into it.

Order **{{ $order->reference }}**

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
