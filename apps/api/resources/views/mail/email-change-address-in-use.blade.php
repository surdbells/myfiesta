<x-mail::message>
# This address already has an account

Somebody asked to move another myFiesta account to this address. It already has an account of its own, so
**nothing changed**, and nothing will.

If that was you, you already have an account here — sign in with this address as usual.

<x-mail::button :url="$signIn">
Sign in
</x-mail::button>

Forgotten the password for it? [Set a new one]({{ $reset }}).

<x-slot:subcopy>
Not you? Ignore this email. Your account is exactly as it was.
</x-slot:subcopy>
</x-mail::message>
