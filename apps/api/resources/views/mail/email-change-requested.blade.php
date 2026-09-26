<x-mail::message>
# Your email address may be about to change

Hello {{ $name }}. Somebody signed in to your myFiesta account asked to use **{{ $new }}** instead of this
address. **Nothing has changed yet** — it only happens if the link we sent to {{ $new }} is opened in the next
{{ $minutes }} minutes.

If that was you, there is nothing to do here.

If it was not, somebody knows your password. Set a new one now: that cancels the change and signs out every
other phone and browser.

<x-mail::button :url="$reset">
Set a new password
</x-mail::button>
</x-mail::message>
