<x-mail::message>
# {{ $event->title }} is waiting for review

We have received **{{ $event->title }}**. Before an event goes on sale on {{ config('app.name') }}, somebody here looks at it the way a buyer will: the description, the poster, the date, the place and the tickets.

**What happens next**

- Most events are looked at within {{ $wait }}.
- If it is approved, it goes on sale straight away and we email you the link.
- If something needs changing, we send it back to you with the reason, and you can change it and send it again.

While it is waiting, the event cannot be changed. If you need to change something, open it in the console and withdraw it from review, then send it again when you are ready.

<x-mail::button :url="$url">
Open the event
</x-mail::button>

Questions? Reply to this email and a person will answer.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
