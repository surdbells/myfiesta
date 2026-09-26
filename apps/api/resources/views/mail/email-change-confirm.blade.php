<x-mail::message>
# Is this your new address?

Somebody asked to move their myFiesta account to this address. **Nothing has changed yet.**

<x-mail::button :url="$url">
Yes, use this address
</x-mail::button>

Once you confirm, this is the address you sign in with and where password resets go, and tickets bought with
the old address move here with the account. Every phone and browser signed in to the account is signed out,
apart from the one the change was asked from.

The link works for {{ $minutes }} minutes, once.

<x-slot:subcopy>
Not expecting this? Ignore it — nothing happens unless the link is opened.
</x-slot:subcopy>
</x-mail::message>
