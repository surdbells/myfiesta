/**
 * What the friend-discount parts of the site say alike.
 *
 * A friend's link rides `?ref=` beside a promoter's, and looks like 'f' and
 * ten base32 characters (ShareLink::SLUG_PATTERN in the API). The shape is
 * only the first check, to spare the server a question about every
 * promoter's link: an older promoter's slug can have it too, and a link
 * stops working when its holder is refunded. Whether a ref really takes money
 * off is the server's to say (ShareApi.friendDiscount, and the quote).
 */
export const FRIEND_LINK = /^f[a-z2-7]{10}$/i;

/** Whether a ref looks like a friend's link. */
export function isFriendLink(ref: string | null | undefined): boolean {
  return !!ref && FRIEND_LINK.test(ref.trim());
}

/** Basis points as a person says them: 1500 as "15%", 1250 as "12.5%". */
export function percentOf(bps: number): string {
  return `${Number((bps / 100).toFixed(2))}%`;
}

/** What checkout says once it has taken the buyer's own link off their order. */
export const OWN_LINK_REFUSED =
  'That’s your own link, so its discount is for your friends. We’ve taken it off: check the new total, then continue.';

/**
 * Whether an order was refused because the friend's link it came by is the
 * buyer's own (`reason: 'own_share_link'` beside the message, a 422).
 */
export function refusedOwnLink(response: unknown): boolean {
  const failed = response as { status?: number; error?: { reason?: unknown } } | null;

  return failed?.status === 422 && failed.error?.reason === 'own_share_link';
}
