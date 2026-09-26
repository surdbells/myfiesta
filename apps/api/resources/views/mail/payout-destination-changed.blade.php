<x-mail::message>
# {{ $first ? 'Payout details added' : 'Payout details changed' }}

@if ($byThem)
Hello {{ $name }}. You {{ $first ? 'added payout details for' : 'changed where payouts go for' }} **{{ $organization }}**, on {{ $when }}.
@else
Hello {{ $name }}. {{ $by }} ({{ $byEmail }}) {{ $first ? 'added payout details for' : 'changed where payouts go for' }} **{{ $organization }}**, on {{ $when }}.
@endif

Payouts now go to:

<x-mail::panel>
{{ $destination }}
</x-mail::panel>

@if ($readsTheSame)
That reads the same as before, because what changed is a part this email leaves out. It is still a change, and it
is checked like one.
@elseif ($previous)
Before this, they went to {{ $previous }}.
@endif

Until we have checked these details with you, usually with a small test deposit, our team is warned before sending
any payout to them. That is a person checking, not a lock on the money, so if this change is news to you, act on it
now.

<x-mail::button :url="$url">
Open Payouts
</x-mail::button>

@if ($byThem)
**If this was not you**, somebody else is signed in to your account. [Set a new password]({{ $reset }}) straight
away — that signs out every other phone and browser — then reply to this email and we will help you put your payout
details right.
@else
**Not expecting this?** Check with {{ $by }}. If it was not them, somebody else is signed in to their account: reply
to this email straight away and we will help you put it right.
@endif
</x-mail::message>
