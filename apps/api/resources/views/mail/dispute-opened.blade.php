<x-mail::message>
# A chargeback on order {{ $reference }}

{{ $amount }} from {{ $night }} ({{ $organizer }}) has been disputed through {{ $processor }}.

**Their reason:** {{ $reason }}. {{ $claim }}

@if ($due)
**Answer by {{ $due }}**{{ $daysLeft !== null ? ' — '.($daysLeft <= 1 ? 'about a day' : $daysLeft.' days').' from now' : '' }}. After that the processor takes no evidence, and the dispute is lost.
@else
The processor gave no deadline. Check it on the dispute's page.
@endif

@if ($found !== null)
The evidence has been put together from our records: {{ $found }} of the {{ $checks }} records that answer this kind of dispute are there. Read it, correct anything that is wrong, and send it — or accept the dispute if the buyer is right.
@else
The evidence could not be put together automatically. Open the dispute to build it from the records.
@endif

@if ($cautions > 0)
<x-mail::panel>
Something in the records suggests the buyer may be right. It is set out at the top of the dispute's page — read it before sending anything.
</x-mail::panel>
@endif

<x-mail::button :url="$link">
Open the dispute
</x-mail::button>

Nothing has been sent to the processor, and nothing will be until one of you sends it.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
