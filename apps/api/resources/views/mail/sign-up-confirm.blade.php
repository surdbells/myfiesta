<x-mail::message>
# Finish setting up your account

Somebody asked to make a myFiesta account with this address. **Nothing has been made yet** — it is made when you
open this link and confirm with the password you chose.

<x-mail::button :url="$url">
Finish setting up
</x-mail::button>

Then sign in with this address and the password you chose. If you have bought tickets here with this address
before, they will be waiting in the account.

The link works for {{ $hours }} hours.

<x-slot:subcopy>
Not expecting this? Ignore it — no account is made unless the link is opened and confirmed.
</x-slot:subcopy>
</x-mail::message>
