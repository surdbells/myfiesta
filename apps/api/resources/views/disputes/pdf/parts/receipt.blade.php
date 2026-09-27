{{-- The order as a receipt: who sold what to whom, for how much, and how it was paid. --}}
@php
    /** @var \App\Services\Disputes\CaseFile $case */
    /** @var \App\Services\Receipts\Receipt $receipt */
    $order = $case->order;
@endphp

<h1>Receipt — order {{ $order->reference }}</h1>
<p class="meta">Issued {{ \App\Services\Disputes\CaseFile::at($receipt->issuedAt) }}. Sold through myFiesta.</p>

<table class="facts">
    <tr><th>Sold by</th><td>{{ $receipt->seller['name'] ?? $case->organizer() ?? '—' }}</td></tr>
    @if ($receipt->service)
        <tr><th>Booking service by</th><td>{{ $receipt->service['name'] ?? 'myFiesta' }}</td></tr>
    @endif
    <tr><th>Bought by</th><td>{{ $order->buyer_name }}@if ($order->buyer_email) ({{ $order->buyer_email }})@endif</td></tr>
    <tr><th>Event</th><td>{{ $case->eventTitle() }}</td></tr>
    @if ($case->eventWhen())
        <tr><th>When</th><td>{{ $case->eventWhen() }}</td></tr>
    @endif
    @if ($case->eventWhere())
        <tr><th>Where</th><td>{{ $case->eventWhere() }}</td></tr>
    @endif
</table>

<h2>What was bought</h2>
<table>
    <tr><th>Item</th><th class="num">Quantity</th><th class="num">Each</th><th class="num">Discount</th><th class="num">Amount</th></tr>
    @foreach ($receipt->lines as $line)
        <tr>
            <td>{{ $line['name'] }}</td>
            <td class="num">{{ $line['quantity'] }}</td>
            <td class="num">{{ $line['unit_price']->format() }}</td>
            <td class="num">{{ $line['discount']->isZero() ? '—' : '−'.$line['discount']->format() }}</td>
            <td class="num">{{ $line['amount']->format() }}</td>
        </tr>
    @endforeach
    <tr><td colspan="4">Subtotal</td><td class="num">{{ $receipt->subtotal->format() }}</td></tr>
    @unless ($receipt->discount->isZero())
        <tr><td colspan="4">Discount</td><td class="num">−{{ $receipt->discount->format() }}</td></tr>
    @endunless
    @foreach ($receipt->taxes as $tax)
        @continue($tax->amount->isZero())
        <tr>
            <td colspan="4">{{ $tax->name }} {{ $tax->percent() }}%{{ $tax->inclusive ? ' (included)' : '' }}</td>
            <td class="num">{{ $tax->amount->format() }}</td>
        </tr>
    @endforeach
    @unless ($receipt->serviceCharge->isZero())
        <tr><td colspan="4">Service charge</td><td class="num">{{ $receipt->serviceCharge->format() }}</td></tr>
    @endunless
    <tr class="total"><td colspan="4">Total charged</td><td class="num">{{ $receipt->total->format() }}</td></tr>
    @unless ($receipt->refunded->isZero())
        <tr><td colspan="4">Refunded since</td><td class="num">−{{ $receipt->refunded->format() }}</td></tr>
    @endunless
</table>

<h2>Payment</h2>
<table class="facts">
    <tr><th>Paid</th><td>{{ \App\Services\Disputes\CaseFile::at($order->paid_at) }}</td></tr>
    <tr><th>Through</th><td>{{ $case->processorName() }}</td></tr>
    @if ($case->cardLine())
        <tr><th>Card</th><td>{{ $case->cardLine() }}</td></tr>
    @endif
    @if ($order->gateway_payment_reference)
        <tr><th>{{ $case->processorName() }} payment</th><td>{{ $order->gateway_payment_reference }}</td></tr>
    @endif
    @if ($case->chargeId())
        <tr><th>{{ $case->processorName() }} charge</th><td>{{ $case->chargeId() }}</td></tr>
    @endif
    @if ($case->statementDescriptor())
        <tr><th>On the statement as</th><td>{{ $case->statementDescriptor() }}</td></tr>
    @endif
    @if ($case->threeDSecureLine())
        <tr><th>3D Secure</th><td>{{ $case->threeDSecureLine() }}</td></tr>
    @endif
    @if ($order->terms_accepted_at)
        <tr><th>Terms accepted</th><td>Version {{ $order->terms_version }}, {{ \App\Services\Disputes\CaseFile::at($order->terms_accepted_at) }}</td></tr>
    @endif
</table>
