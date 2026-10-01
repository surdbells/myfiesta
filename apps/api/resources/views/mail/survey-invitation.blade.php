<x-mail::message>
# How was {{ $event->title }}?

Thanks for coming on {{ $night }}. {{ $organizer }} would like to know how the
night went for you — {{ $questions === 1 ? 'one question' : $questions.' short questions' }}, about a minute.

<x-mail::button :url="$url">
Tell them how it was
</x-mail::button>

Your answers go to {{ $organizer }} without your name or email address.

<x-slot:subcopy>
You are getting this because you had a ticket to this event.
[Stop emails like this]({{ $unsubscribeUrl }}) — this will not affect your tickets
or your order emails.
</x-slot:subcopy>
</x-mail::message>
