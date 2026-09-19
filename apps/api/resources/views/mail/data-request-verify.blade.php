<x-mail::message>
# Is this you?

@if ($erasure)
Somebody asked us to erase everything myFiesta holds about this address. **Nothing has happened yet.**

If it was you, confirm below. Your account, if you have one, is closed, and your name and address are
removed from everything that is not a financial record we are required to keep — we will tell you exactly
what that leaves behind.
@else
Somebody asked us for a copy of everything myFiesta holds about this address. **Nothing has been sent yet.**

If it was you, confirm below and we will put the file together straight away.
@endif

<x-mail::button :url="$url">
@if ($erasure) Yes, erase me @else Yes, send me my data @endif
</x-mail::button>

If it was not you, ignore this email. The link stops working after {{ $hours }} hours and nothing will have
changed.
</x-mail::message>
