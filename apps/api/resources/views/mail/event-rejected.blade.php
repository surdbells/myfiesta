<x-mail::message>
# {{ $event->title }} needs changes

We have looked at **{{ $event->title }}** and it cannot go on sale as it is. This is what our reviewer wrote:

<x-mail::panel>
{{ $reason }}
</x-mail::panel>

**What to do now**

1. Open the event in the console. It is a draft again, so you can change it.
2. Change what the reviewer describes above.
3. Press **Submit for review** to send it again. We will look at it as soon as we can.

If something above is not clear, or you think it is a mistake, reply to this email and a person will answer.

<x-mail::button :url="$url">
Open the event
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
