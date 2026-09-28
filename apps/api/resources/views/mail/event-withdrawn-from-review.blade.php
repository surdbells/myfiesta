<x-mail::message>
# {{ $event->title }} is a draft again

**{{ $event->title }}** was taken back from review, so we will not look at it for now. It is a draft: you can change anything about it.

When it is ready, press **Submit for review** in the console to send it again.

<x-mail::button :url="$url">
Open the event
</x-mail::button>

Did not mean to do this? Send it again from the console — nothing about it has been lost.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
