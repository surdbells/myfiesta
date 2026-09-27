<x-mail::message>
# {{ $daysLeft <= 1 ? 'About a day' : $daysLeft.' days' }} left on the chargeback on order {{ $reference }}

{{ $amount }} from {{ $night }} is still disputed ({{ $reason }}), and nobody has answered it yet.

**Answer by {{ $due }}.** After that the processor takes no evidence, and the dispute is lost.

@if ($drafted)
The evidence is ready to read on the dispute's page. Send it, or accept the dispute if the buyer is right.
@else
The evidence has not been put together yet. Open the dispute to build it from the records.
@endif

<x-mail::button :url="$link">
Open the dispute
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
