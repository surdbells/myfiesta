<?php

namespace App\Services\Organizations;

use App\Http\Controllers\Api\DoorController;
use App\Http\Controllers\Api\Organizer\CampaignController;
use App\Http\Controllers\Api\Organizer\EventController;
use App\Http\Controllers\Api\Organizer\PayoutController;
use App\Http\Controllers\Api\Organizer\WaitlistController;
use Illuminate\Http\Request;

/**
 * What an organization cannot do while it is suspended.
 *
 * Only what would put something on sale, sell it, or pay it out. Everything
 * else in the console stays open, reading and writing alike: drafts, ticket
 * types, codes, messages to ticket holders, refunds, the door. A suspension
 * stops the organization selling; it does not stop it looking after the
 * people who already bought, and the door in particular has to keep working
 * on the night so that everybody who paid gets in.
 *
 * Listed by controller action, like WhileImpersonating, so
 * SuspensionBoundary can refuse before a controller runs with one sentence
 * that says why. The services behind the ones that matter check again —
 * CheckoutService refuses the sale, PayoutRequests the request, and
 * SettlementRecorder the payout — so a route added later that reaches them
 * is still refused, only less politely.
 */
final class WhileSuspended
{
    public const PUBLISH = 'Your organization is suspended, so events cannot be put on sale. Everything else in the console still works.';

    public const SELL = 'Your organization is suspended, so tickets cannot be sold: online, at the door, or from the waitlist. Tickets already sold still get people in.';

    public const PAYOUTS = 'Your organization is suspended, so payouts are paused. A payout you have already asked for is held, not rejected, and goes ahead once the suspension is lifted.';

    public const CAMPAIGNS = 'Your organization is suspended, so campaigns cannot be sent or scheduled. You can still write to the people holding tickets from each event.';

    /**
     * Endpoints refused outright while suspended, by controller action.
     *
     * @var array<string, string>
     */
    public const REFUSED = [
        // Asking to be paid. Withdrawing a request stays open.
        PayoutController::class.'@requestPayout' => self::PAYOUTS,

        // Selling at the door. Scanning, the offline list and the till stay
        // open: the door works for everybody who already holds a ticket.
        DoorController::class.'@quote' => self::SELL,
        DoorController::class.'@sell' => self::SELL,

        // Telling the waitlist tickets are available sends people to a sale
        // that cannot happen.
        WaitlistController::class.'@notify' => self::SELL,

        // Selling to people who might come, in the organization's name.
        // Cancelling a campaign not yet sent stays open.
        CampaignController::class.'@store' => self::CAMPAIGNS,
        CampaignController::class.'@update' => self::CAMPAIGNS,

        // Sending an event for review is the start of putting it on sale
        // (EventReviews checks again). Taking one back from review stays open.
        EventController::class.'@submit' => self::PUBLISH,
    ];

    /**
     * Refused only for some requests: publishing, but not taking an event
     * off sale — an organizer who wants a night off the site while they sort
     * things out can still do that, and it stays off when the suspension is
     * lifted.
     *
     * @var list<string>
     */
    public const REFUSED_WHEN = [
        EventController::class.'@publish',
    ];

    /** Whether this action is one a suspension could refuse, before looking anything up. */
    public static function guards(?string $action): bool
    {
        return $action !== null
            && (array_key_exists($action, self::REFUSED) || in_array($action, self::REFUSED_WHEN, true));
    }

    /**
     * Why this request is refused while the organization is suspended, or
     * null when it is not one a suspension stops.
     */
    public static function refusalFor(?string $action, Request $request): ?string
    {
        if ($action === null) {
            return null;
        }

        if (array_key_exists($action, self::REFUSED)) {
            return self::REFUSED[$action];
        }

        return match ($action) {
            EventController::class.'@publish' => $request->input('status') === 'published' ? self::PUBLISH : null,
            default => null,
        };
    }

    /**
     * Where to write about it, when the deployment says.
     *
     * Added to every refusal and to the console's banner, so "contact
     * support" is always an address rather than an instruction to go and
     * find one.
     */
    public static function supportEmail(): ?string
    {
        $address = config('myfiesta.contact.support_email') ?: config('mail.support.address');

        return filled($address) ? trim((string) $address) : null;
    }

    /** The refusal, with where to ask about it. */
    public static function withContact(string $message): string
    {
        $support = self::supportEmail();

        return $support === null
            ? $message.' Contact myFiesta support to have the suspension looked at.'
            : $message.' Write to '.$support.' to have the suspension looked at.';
    }
}
