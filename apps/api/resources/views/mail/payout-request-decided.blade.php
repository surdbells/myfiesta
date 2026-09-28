<x-mail::message>
@if ($request->status === 'paid')
# {{ $paid }} is on its way

We sent the payout {{ $organization->name }} asked for.

@if ($request->paid_amount !== $request->amount)
You asked for {{ $asked }}; {{ $paid }} was sent.
@if ($request->decision_note)
{{ $request->decision_note }}
@endif
@endif

@if ($advance)
{{ $advance }} of this is an advance from myFiesta, ahead of your sales. Your next sales in {{ $request->currency }} pay it back automatically, and you can ask to be paid again once your balance is above zero. Your Payouts page shows how much is left.

@endif
Transfers can take a day or two to arrive, depending on your bank.
@else
# Your payout request was not paid

{{ $organization->name }} asked for {{ $asked }}. It has not been sent, for this reason:

<x-mail::panel>
{{ $request->decision_note }}
</x-mail::panel>

Once that is sorted, you can ask again from your Payouts page.
@endif

<x-mail::button :url="$url">
Open Payouts
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
