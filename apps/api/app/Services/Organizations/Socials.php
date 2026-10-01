<?php

namespace App\Services\Organizations;

use App\Models\Organization;

/**
 * Where else to find an organizer, read the same way whoever typed it.
 *
 * The old platform kept whatever an organizer pasted: a full Instagram
 * address one time, "@lagosnights" the next, a link with tracking on the end
 * the time after. The legacy importer carried those across as they were, so
 * every one is read through here before it reaches a page — and anything that
 * cannot be read as an account on that network is left off, rather than shown
 * as a link to somewhere nobody meant.
 *
 * What is kept is the part that names the account (the username, or the page
 * for Facebook) and never a whole address, so the page builds the link itself
 * and a value in the database cannot send a reader to another site. The one
 * exception is the organizer's own website, which is an address by nature and
 * is only ever https.
 */
class Socials
{
    /** In the order a page shows them. */
    public const NETWORKS = ['instagram', 'tiktok', 'x', 'facebook', 'website'];

    /** Each network's column, where it is not the network's own name. */
    public const COLUMNS = [
        'instagram' => 'instagram',
        'tiktok' => 'tiktok',
        'x' => 'x_handle',
        'facebook' => 'facebook',
        'website' => 'website',
    ];

    /** Instagram's own rule: letters, numbers, full stops and underscores, 30 at most. */
    public const INSTAGRAM = '/^[A-Za-z0-9._]{1,30}$/';

    /** X's: letters, numbers and underscores, 15 at most. */
    public const X = '/^[A-Za-z0-9_]{1,15}$/';

    /** TikTok's: letters, numbers, full stops and underscores, 2 to 24. */
    public const TIKTOK = '/^[A-Za-z0-9._]{2,24}$/';

    /**
     * A Facebook page's name in its address.
     *
     * Facebook asks for letters, numbers and full stops today; pages named
     * years ago have hyphens, and those addresses still work.
     */
    public const FACEBOOK = '/^[A-Za-z0-9.\-]{2,75}$/';

    /** The longest value the columns hold (TikTok's is shorter, and its rule shorter still). */
    public const LONGEST = 255;

    private const HOSTS = [
        'instagram' => ['instagram.com', 'instagr.am'],
        'x' => ['x.com', 'twitter.com'],
        'tiktok' => ['tiktok.com'],
        'facebook' => ['facebook.com', 'fb.com'],
    ];

    /**
     * What people type when there is nothing to type.
     *
     * Every one of these passes Instagram's rule, and none of them is the
     * organizer's account.
     */
    private const PLACEHOLDERS = ['none', 'nil', 'na', 'n.a', 'null', 'nope', 'nothing', 'no'];

    /**
     * The first part of an address that is a page of the network's own — a
     * post, a share button, a search — rather than somebody's account.
     */
    private const NOT_ACCOUNTS = [
        'instagram' => ['p', 'reel', 'reels', 'tv', 'stories', 'explore', 'accounts', 'direct'],
        'x' => ['i', 'intent', 'share', 'home', 'search', 'hashtag', 'explore', 'settings'],
        'tiktok' => [],
        'facebook' => [
            'share', 'sharer', 'sharer.php', 'watch', 'events', 'photo', 'photo.php', 'story.php',
            'permalink.php', 'login', 'home.php', 'hashtag', 'search', 'marketplace', 'groups',
        ],
    ];

    /**
     * The organization's accounts as stored, each made sense of or null.
     *
     * What the console's form starts from, so an organizer whose imported
     * value could not be read sees an empty box rather than a link that would
     * never be shown.
     *
     * @return array{instagram: ?string, tiktok: ?string, x: ?string, facebook: ?string, website: ?string}
     */
    public static function of(Organization $organization): array
    {
        $out = [];

        foreach (self::COLUMNS as $network => $column) {
            $out[$network] = self::normalise($network, $organization->getAttribute($column));
        }

        /** @var array{instagram: ?string, tiktok: ?string, x: ?string, facebook: ?string, website: ?string} $out */
        return $out;
    }

    /**
     * The links a page shows, in order, leaving out what is not there.
     *
     * The label is what the link says: the @username where the network uses
     * one, the page name for Facebook, the site's own name for a website.
     *
     * @return list<array{network: string, label: string, url: string}>
     */
    public static function links(Organization $organization): array
    {
        $links = [];

        foreach (self::of($organization) as $network => $value) {
            if ($value !== null) {
                $links[] = ['network' => $network, ...self::link($network, $value, (string) $organization->name)];
            }
        }

        return $links;
    }

    /**
     * The value for one network, as it is kept, or null if it cannot be read
     * as an account there.
     *
     * Takes a bare username, the same with an @ in front, or the address of
     * the account on that network with or without its https://, a www. or
     * anything after a question mark.
     */
    public static function normalise(string $network, mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }

        $value = trim($raw);

        if ($value === '' || in_array(strtolower($value), self::PLACEHOLDERS, true)) {
            return null;
        }

        $kept = match ($network) {
            'instagram' => self::account($value, 'instagram', self::INSTAGRAM),
            'x' => self::account($value, 'x', self::X),
            'tiktok' => self::account($value, 'tiktok', self::TIKTOK),
            'facebook' => self::facebook($value),
            'website' => self::website($value),
            default => null,
        };

        // What is kept has to fit its column. An address that grows past it
        // once its accents are encoded is refused here, as unreadable, rather
        // than turned away by the database after the form was accepted.
        return $kept !== null && strlen($kept) <= self::LONGEST ? $kept : null;
    }

    /**
     * The address and the words for one kept value.
     *
     * @return array{label: string, url: string}
     */
    private static function link(string $network, string $value, string $organizer): array
    {
        return match ($network) {
            'instagram' => ['label' => '@'.$value, 'url' => 'https://www.instagram.com/'.$value.'/'],
            'x' => ['label' => '@'.$value, 'url' => 'https://x.com/'.$value],
            'tiktok' => ['label' => '@'.$value, 'url' => 'https://www.tiktok.com/@'.$value],
            // A page kept by its address (profile.php?id=…, /p/…) has no
            // username to show, and the pages show "Facebook" in front of
            // the label already, so it goes by the organizer's own name.
            'facebook' => str_starts_with($value, 'https://')
                ? ['label' => $organizer !== '' ? $organizer : 'page', 'url' => $value]
                : ['label' => $value, 'url' => 'https://www.facebook.com/'.$value],
            default => ['label' => self::host($value), 'url' => $value],
        };
    }

    /** An Instagram, X or TikTok username, from a username or an address. */
    private static function account(string $value, string $network, string $rule): ?string
    {
        $address = self::address($value, self::HOSTS[$network]);

        if ($address === null) {
            return null;
        }

        if ($address === false) {
            $handle = ltrim($value, '@');
        } else {
            $segments = $address['segments'];

            // instagram.com/_u/name is the app's own way of linking to one.
            if ($network === 'instagram' && ($segments[0] ?? null) === '_u') {
                array_shift($segments);
            }

            $handle = $segments[0] ?? '';

            if (in_array(strtolower($handle), self::NOT_ACCOUNTS[$network], true)) {
                return null;
            }

            // A TikTok account's address is tiktok.com/@name. Anything else
            // there — a video, a short link — names no account.
            if ($network === 'tiktok' && ! str_starts_with($handle, '@')) {
                return null;
            }

            $handle = ltrim($handle, '@');
        }

        return preg_match($rule, $handle) === 1 ? $handle : null;
    }

    /**
     * A Facebook page's name, or the page's address when it has no name.
     *
     * Pages that never chose a name are reached as profile.php?id=… or
     * /p/Name-123…, and older ones under /pages/… or /people/…; those are
     * kept as a tidy address on www.facebook.com, built here from the parts
     * that were read.
     */
    private static function facebook(string $value): ?string
    {
        $address = self::address($value, self::HOSTS['facebook']);

        if ($address === null) {
            return null;
        }

        if ($address === false) {
            $slug = ltrim($value, '@');

            return preg_match(self::FACEBOOK, $slug) === 1 ? $slug : null;
        }

        $segments = $address['segments'];
        $first = $segments[0] ?? '';

        if ($first === 'profile.php') {
            $id = $address['query']['id'] ?? null;

            return is_string($id) && preg_match('/^\d{1,20}$/', $id) === 1
                ? 'https://www.facebook.com/profile.php?id='.$id
                : null;
        }

        if (in_array(strtolower($first), self::NOT_ACCOUNTS['facebook'], true)) {
            return null;
        }

        // Pages without a username: /p/Name-123… today, /pages/… and
        // /people/… before that. Only the first segment after /p/ is the page.
        if ($first === 'p' || $first === 'pages' || $first === 'people') {
            $rest = array_slice($segments, 0, $first === 'p' ? 2 : 4);

            if (count($rest) < 2) {
                return null;
            }

            $named = [];

            foreach ($rest as $segment) {
                // A page's name can have accents in it, typed as they are or
                // as a browser copies them (Caf%C3%A9). Both are read as the
                // letters they stand for, and kept encoded, so what is kept
                // reads back as itself.
                $decoded = rawurldecode($segment);

                if (preg_match('/^[\pL\pM\pN.\-_ ]{1,100}$/u', $decoded) !== 1) {
                    return null;
                }

                $named[] = rawurlencode($decoded);
            }

            return 'https://www.facebook.com/'.implode('/', $named);
        }

        return preg_match(self::FACEBOOK, $first) === 1 ? $first : null;
    }

    /**
     * The organizer's own site, as an https address.
     *
     * "lagosnights.com" is taken to mean https://lagosnights.com. A plain
     * http address is refused rather than upgraded: a site that does not
     * answer on https would be a broken link under their name.
     */
    private static function website(string $value): ?string
    {
        if (preg_match('~^[a-z][a-z0-9+.\-]*://~i', $value) !== 1) {
            $value = 'https://'.$value;
        }

        if (strlen($value) > 255 || preg_match('/\s/', $value) === 1) {
            return null;
        }

        $parts = parse_url($value);

        if ($parts === false || strtolower($parts['scheme'] ?? '') !== 'https') {
            return null;
        }

        $host = strtolower($parts['host'] ?? '');

        // A name with a dot in it, and no password in front of it.
        if (
            isset($parts['user']) || isset($parts['pass'])
            || preg_match('/^([a-z0-9-]+\.)+[a-z]{2,}$/', $host) !== 1
        ) {
            return null;
        }

        return 'https://'.$host
            .(isset($parts['port']) ? ':'.$parts['port'] : '')
            .($parts['path'] ?? '')
            .(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    /** "lagosnights.com", from https://www.lagosnights.com/about. */
    private static function host(string $url): string
    {
        $host = (string) parse_url($url, PHP_URL_HOST);

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /**
     * The path and query of an address on one of these hosts.
     *
     * False when the value is not an address at all (a bare username); null
     * when it is an address somewhere else, which names no account here.
     *
     * @param  list<string>  $hosts
     * @return array{segments: list<string>, query: array<string, mixed>}|false|null
     */
    private static function address(string $value, array $hosts): array|false|null
    {
        $schemed = preg_match('~^[a-z][a-z0-9+.\-]*://~i', $value) === 1;
        $named = preg_match('~^([a-z0-9-]+\.)*('.implode('|', array_map('preg_quote', $hosts)).')(/|\?|$)~i', $value) === 1;

        // A bare username: "lagosnights", "lagos.nights", "@lagos_nights".
        if (! $schemed && ! $named && ! str_contains($value, '/')) {
            return false;
        }

        $parts = parse_url($schemed ? $value : 'https://'.$value);

        if ($parts === false) {
            return null;
        }

        $host = strtolower($parts['host'] ?? '');
        $ours = false;

        foreach ($hosts as $candidate) {
            if ($host === $candidate || str_ends_with($host, '.'.$candidate)) {
                $ours = true;
            }
        }

        if (! $ours) {
            return null;
        }

        parse_str($parts['query'] ?? '', $query);

        return [
            'segments' => array_values(array_filter(explode('/', $parts['path'] ?? ''), fn ($s) => $s !== '')),
            'query' => $query,
        ];
    }
}
