{{--
    Every document in one, for a processor that takes one file with an answer
    (Paystack). The same parts as the separate documents, in the same words.
--}}
@extends('disputes.pdf.layout')

@section('content')
    @include('disputes.pdf.parts.receipt')
    <div class="break"></div>
    @include('disputes.pdf.parts.service-documentation')
    <div class="break"></div>
    @include('disputes.pdf.parts.refund-policy')
    <div class="break"></div>
    @include('disputes.pdf.parts.customer-communication')
@endsection
