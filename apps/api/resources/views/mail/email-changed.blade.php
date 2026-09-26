<x-mail::message>
# Your email address has changed

Hello {{ $name }}. Your myFiesta account now uses **{{ $new }}**. You sign in with that from now on, and
password resets go there instead of to {{ $old }}. Tickets bought with {{ $old }} moved with the account, so
reminders and organizers' messages about them go to the new address too.

Every phone and browser that was signed in has been signed out, apart from the one the change was asked from.

If this was not you, reply to this email straight away. Somebody who knew your password has moved your
account, and we will help you get it back.
</x-mail::message>
