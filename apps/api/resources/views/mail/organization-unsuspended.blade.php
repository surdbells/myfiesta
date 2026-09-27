<x-mail::message>
# {{ $organization->name }} is no longer suspended

We have lifted the suspension on **{{ $organization->name }}**. You can sell tickets and publish events again, and payouts have resumed. Any payout request that was held is waiting for us again, in its place in the queue.

@if (count($backOnSale) > 0)
**Back on sale**

@foreach ($backOnSale as $title)
- {{ $title }}
@endforeach
@endif

@if (count($leftAsDrafts) > 0)
**Not back on sale**

These were on sale when the suspension began and have not gone back on sale: they have already happened, or were cancelled, deleted or taken down since.

@foreach ($leftAsDrafts as $title)
- {{ $title }}
@endforeach
@endif

<x-mail::button :url="$url">
Open your events
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
