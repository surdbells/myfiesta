<?php

namespace App\Mail;

use App\Mail\Concerns\KeepsTypedTextPlain;
use App\Mail\Concerns\RepliesReachSupport;
use App\Models\Organization;
use App\Models\OrganizationPayoutDetail;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "Payouts for Lagos Nights now go somewhere else", to every owner.
 *
 * Changing where the money goes is how a stolen owner account turns into
 * stolen money, and the person whose account it is may be the last to know.
 * So every owner hears about it — the one who made the change as well, because
 * if it was not them this is how they find out their account is in use — and
 * each is told who did it, when, and what to do if nobody meant to.
 *
 * What it says about the destination is what is safe to leave in an inbox for
 * years: the bank's name and the last four digits, as on the Payouts page, and
 * never the account number. The Interac address is shown in full on that page
 * to anybody who can see the money, but here it is cut to its first letter and
 * its domain: enough for an owner to tell their own address from a stranger's.
 * The audit log leaves it out altogether, because the log outlives a request to
 * be erased — and an email outlives that too, in inboxes nobody here can
 * reach. The page is a button away for the rest.
 *
 * What happens next is said as it actually happens. Saving new details clears
 * their verification, and staff are warned before paying to details nobody has
 * checked — but they can still go ahead with a reason on the record
 * (SettlementRecorder). So the email says a person checks, not that the money
 * is held: an owner who believed it was held would see no reason to hurry.
 *
 * Built from what was true at the moment of the change and nothing read later,
 * so a second change before the queue gets to this one cannot put the second
 * destination under the first person's name. Encrypted on the queue, because
 * the bank's name is encrypted everywhere else it is kept.
 *
 * The names, the addresses and the bank's name were all typed by somebody —
 * after a takeover, the person this warns about — hence KeepsTypedTextPlain.
 *
 * Queued, like PayoutRequestDecided. The email-change links are sent straight
 * away because a queued copy of their token would sit in the jobs table; this
 * carries no token, so a copy on the queue gives nobody anything to use.
 */
class PayoutDestinationChanged extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use KeepsTypedTextPlain, Queueable, RepliesReachSupport;

    public readonly string $organization;

    public readonly string $name;

    public readonly string $by;

    public readonly string $byEmail;

    public readonly bool $byThem;

    public readonly string $when;

    /**
     * @param  User  $owner  who this is for
     * @param  User  $by  who made the change
     * @param  string  $destination  where payouts go now, from describe()
     * @param  string|null  $previous  where they went before, from describe(); null the first time
     * @param  string  $fallbackZone  the zone to write the time in when the owner has not chosen one
     */
    public function __construct(
        User $owner,
        User $by,
        Organization $organization,
        public readonly string $destination,
        public readonly ?string $previous,
        CarbonInterface $at,
        string $fallbackZone,
    ) {
        $this->organization = $organization->name;
        $this->name = $owner->name;
        $this->by = $by->name;
        $this->byEmail = $by->email;
        $this->byThem = $owner->is($by);

        // The reader's own zone if they have set one, since it is their memory
        // of that evening being asked about. Otherwise the zone the
        // organization's events are in, with the abbreviation either way so
        // nobody has to guess.
        $zone = self::zone($owner->timezone) ?? self::zone($fallbackZone) ?? 'UTC';

        $this->when = CarbonImmutable::instance($at)->setTimezone($zone)->format('l j F Y \a\t g:i a T');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            replyTo: $this->supportReplyTo(),
            subject: ($this->previous === null ? 'Payout details added' : 'Payout details changed')
                .' for '.$this->organization,
        );
    }

    public function content(): Content
    {
        $console = rtrim((string) config('app.console_url'), '/');

        return new Content(
            markdown: 'mail.payout-destination-changed',
            with: [
                'organization' => $this->organization,
                'name' => $this->name,
                'by' => $this->by,
                'byEmail' => $this->byEmail,
                'byThem' => $this->byThem,
                'when' => $this->when,
                'first' => $this->previous === null,
                'destination' => $this->destination,
                'previous' => $this->previous,
                // Same bank and last four with a different transit number is a
                // different account; so is one Gmail address starting with m
                // for another. Said, so "before" and "now" reading alike is not
                // taken for nothing having happened.
                'readsTheSame' => $this->previous === $this->destination,
                'url' => $console.'/payouts',
                'reset' => $console.'/forgot-password',
            ],
        );
    }

    /**
     * Where the money goes, in words that are safe in an email.
     *
     * "Bank transfer to Royal Bank, account ending 4567", or "Interac
     * e-Transfer to m•••@lagosnights.test". The rail's name is the console's,
     * so the email and the Payouts page describe the same thing the same way.
     * Taken before and after a save, it is also the "before this" line.
     */
    public static function describe(OrganizationPayoutDetail $detail): string
    {
        if ($detail->rail === 'interac') {
            return 'Interac e-Transfer to '.self::maskAddress((string) $detail->interac_email);
        }

        $bank = trim((string) $detail->bank_name);
        $account = 'account ending '.($detail->account_last_four ?? '????');

        return 'Bank transfer to '.($bank === '' ? $account : "{$bank}, {$account}");
    }

    /** m•••@lagosnights.test: the first letter, and where it is kept. */
    private static function maskAddress(string $address): string
    {
        $at = strrpos($address, '@');

        if ($at === false || $at === 0) {
            return '•••';
        }

        return mb_substr($address, 0, 1).'•••'.substr($address, $at);
    }

    /** The zone if it is one PHP knows, so an odd value on an old account cannot stop the email. */
    private static function zone(?string $zone): ?string
    {
        return $zone !== null && in_array($zone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)
            ? $zone
            : null;
    }
}
