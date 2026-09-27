<?php

namespace App\Services\Accounts;

use App\Models\Order;
use App\Models\User;

/**
 * Having agreed to the terms, the privacy policy and the refund policy.
 *
 * Asked in two places — signing up, and buying — and kept so it can be shown
 * later: which version of the words, and when. The box on each screen starts
 * unticked; a box ticked for somebody is not somebody agreeing.
 *
 * Only the version and the moment are kept. Not the address the request came
 * from and not the browser: the privacy page does not say we hold either, and
 * that page is written from what the platform actually holds.
 */
class Terms
{
    /**
     * What a refusal says, on either form.
     *
     * Plain, and the same words on the sign-up and the checkout, since it is
     * the same box and the same three pages.
     */
    public const REFUSAL = 'Tick the box to accept the terms, the privacy policy and the refund policy.';

    /** The version in force now. See config/terms.php. */
    public function current(): string
    {
        return (string) config('terms.version');
    }

    /**
     * Whether this account has already agreed to the words in force now.
     *
     * An older version does not count. Changed words are what an account is
     * asked about again — once, at its next checkout that knows who is
     * buying. The public checkout does not today (CreateOrderRequest), so
     * there everybody is asked, every time. The console and the phone's
     * organizer screens ask too, for somebody who never buys (TermsController).
     */
    public function acceptedBy(?User $user): bool
    {
        return $user !== null
            && $user->terms_accepted_at !== null
            && $user->terms_version === $this->current();
    }

    /**
     * Say on the account that its holder agreed, just now, to the words in
     * force now.
     *
     * For a buyer the checkout knows, who ticked the box. A sign-up waiting
     * for its link carries its own moment instead (SignUps::complete()), and
     * an account made by joining an invitation is given it as it is made.
     */
    public function recordFor(User $user): void
    {
        $user->forceFill([
            'terms_version' => $this->current(),
            'terms_accepted_at' => now(),
        ])->save();
    }

    /**
     * Keep with an order what its buyer agreed to.
     *
     * A buyer who ticked the box agreed now, to the words in force now, and a
     * signed-in one who had not agreed to them before is not asked again at
     * the next checkout. A signed-in buyer who was not shown the box — their
     * account had already agreed to these words — has that agreement copied
     * onto the order, so every order answers the question the same way,
     * however it was placed.
     *
     * The public checkout passes nobody as the buyer today, since its route
     * reads no sign-in: its orders take the first branch and never touch an
     * account.
     */
    public function recordOn(Order $order, ?User $buyer, bool $ticked): void
    {
        if ($ticked) {
            $version = $this->current();
            $at = now();

            if ($buyer !== null && ! $this->acceptedBy($buyer)) {
                $this->recordFor($buyer);
            }
        } elseif ($this->acceptedBy($buyer)) {
            $version = $buyer->terms_version;
            $at = $buyer->terms_accepted_at;
        } else {
            // Not reachable through the checkout, which refuses an order
            // without one of the two. Recording nothing is the honest outcome
            // for any other caller.
            return;
        }

        $order->forceFill([
            'terms_version' => $version,
            'terms_accepted_at' => $at,
        ])->save();
    }
}
