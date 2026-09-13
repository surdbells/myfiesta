<x-mail::message>
# Join {{ $organization }}

@if ($inviter)
{{ $inviter }} has invited you to {{ $organization }} as **{{ $role }}**.
@else
You have been invited to {{ $organization }} as **{{ $role }}**.
@endif

As {{ $role }} you can {{ $can }}.

<x-mail::button :url="$url">
Accept the invitation
</x-mail::button>

The link works for {{ $days }} days, and only for this email address.

<x-slot:subcopy>
Not expecting this? You can ignore it — nothing happens unless you accept.
</x-slot:subcopy>
</x-mail::message>
