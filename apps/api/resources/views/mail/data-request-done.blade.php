<x-mail::message>
@if ($refused)
# We could not do that yet

{{ $refused }}

Nothing has been erased, and you can ask again as soon as that is sorted.
@elseif ($erasure)
# You have been erased

Your name and address are gone from everything we could remove them from, and your account is closed.

@if ($kept)
Some records stay, without you attached to them: orders, tickets and the entries that record money moving.
The law requires us to keep those for seven years, and they no longer say who you are. One thing does keep
your address on purpose — the list that stops us emailing you — because forgetting that would start the
emails again.
@endif
@else
# Your data is ready

Everything we hold that is about you, as one file.

<x-mail::button :url="$url">
Download it
</x-mail::button>

The link works for {{ $days }} days, then the file is deleted. Ticket codes are not in it — those open
doors, and your tickets are always in the link from your confirmation email.
@endif

Nobody will ask you why you asked.
</x-mail::message>
