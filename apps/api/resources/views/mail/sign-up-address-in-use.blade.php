<x-mail::message>
# You already have an account

Somebody tried to sign up to myFiesta with this address. It already has an account, so **nothing new was made**,
and nothing about your account changed.

If that was you, sign in with this address as usual.

<x-mail::button :url="$signIn">
Sign in
</x-mail::button>

Forgotten the password? [Set a new one]({{ $reset }}).

<x-slot:subcopy>
Not you? Ignore this email. Your account is exactly as it was.
</x-slot:subcopy>
</x-mail::message>
