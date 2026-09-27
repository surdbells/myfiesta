<x-mail::message>
# Confirm your email address

Open this link and confirm with your myFiesta password to show this address is yours. Until you do, your account
can do everything except publish an event or change anything about payouts.

<x-mail::button :url="$url">
Confirm this address
</x-mail::button>

The link works for {{ $hours }} hours.

<x-slot:subcopy>
Not expecting this? Ignore it — nothing changes unless the link is opened and confirmed.
</x-slot:subcopy>
</x-mail::message>
