{{--
    The refund policy in the words of the version the buyer accepted — kept in
    resources/legal and never edited once in force — and exactly how it was
    put in front of them.
--}}
@php
    /** @var \App\Services\Disputes\CaseFile $case */
    $order = $case->order;
@endphp

<h1>Refund policy as shown — order {{ $order->reference }}</h1>
<p class="meta">Terms version {{ $order->terms_version ?? 'not recorded' }}.</p>

<h2>How it was shown, and accepted</h2>
@if ($order->terms_accepted_at)
    <table class="facts">
        <tr><th>Where</th><td>The myFiesta checkout{{ $site !== '' ? ' ('.$site.')' : '' }}, before payment.</td></tr>
        <tr><th>How</th><td>A box, unticked to begin with, beside the words “{{ $checkbox }}”, each linked to its page{{ $site !== '' ? ' ('.$site.'/terms, '.$site.'/privacy, '.$site.'/refunds)' : '' }}. The checkout will not take an order until it is ticked.</td></tr>
        @if ($case->policySummary)
            <tr><th>Beside the box</th><td>“{{ $case->policySummary }}”</td></tr>
            @if ($case->gateway() === 'stripe')
                <tr><th>Beside Stripe's pay button</th><td>The same sentence.</td></tr>
            @endif
        @endif
        <tr><th>Accepted</th><td>Version {{ $order->terms_version }}, {{ \App\Services\Disputes\CaseFile::at($order->terms_accepted_at) }}, by {{ $order->buyer_name }}</td></tr>
        @if ($case->stripeConsent())
            <tr><th>Stripe's own terms box</th><td>{{ $case->stripeConsent() === 'accepted' ? 'Ticked on Stripe\'s payment page, as recorded by Stripe' : $case->stripeConsent() }}</td></tr>
        @endif
    </table>
@else
    <p class="none">This order has no record of the terms being accepted.</p>
@endif

<h2>The policy, as it read under version {{ $order->terms_version ?? '—' }}</h2>
@if ($policyHtml)
    <div class="policy">{!! $policyHtml !!}</div>
@else
    <p class="none">No copy of the refund policy is kept for this order's terms version.</p>
@endif
