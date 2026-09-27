<?php

namespace App\Support;

use App\Services\Backups\BackupCipher;
use RuntimeException;

/**
 * What production must not start without.
 *
 * Every item here is a setting whose absence does not fail loudly. A blank
 * webhook secret takes payments and never marks them paid — or, before the
 * gateways refused it, marked paid whatever anybody sent. A log mailer and a
 * log text driver both report success for messages that went nowhere. A debug
 * flag left on shows a stranger the configuration. Each of those looks like a
 * working platform until a buyer is standing at a door with no ticket.
 *
 * So production refuses to serve with any of them wrong, and says which. The
 * list is read from config rather than the environment, so it agrees with
 * whatever `config:cache` froze, and it is gathered whole: somebody fixing a
 * deployment should see every problem at once, not one per restart.
 *
 * Enforced in three places: `php artisan app:preflight` in the container's
 * start command, a listener that stops the worker and scheduler starting, and
 * a check as each web request boots in case something started php-fpm without
 * the first. Build-time commands — package:discover, config:cache and the rest
 * — are left alone, because they run without secrets by design.
 */
final class Preflight
{
    /**
     * The commands that serve, as opposed to the ones that build or repair.
     *
     * These are the ones refused. Anything else still runs, so an operator can
     * cache config, run a migration or open tinker on a box that is not yet
     * right — that is how it gets put right.
     */
    public const SERVING_COMMANDS = [
        'queue:work',
        'queue:listen',
        'schedule:work',
        'schedule:run',
        'horizon',
        'octane:start',
        'serve',
    ];

    /** Mail transports that take a message and deliver it nowhere. */
    private const PRETEND_MAILERS = ['log', 'array'];

    /** Trusting these is trusting everybody, which is the problem, not a setting. */
    private const EVERYBODY = ['*', '**', '0.0.0.0/0', '::/0'];

    /**
     * Everything production would refuse, keyed by the variable to change.
     *
     * @return array<string, string>
     */
    public static function problems(): array
    {
        $problems = [];

        if (blank(config('app.key'))) {
            $problems['APP_KEY'] = 'is empty. Payout details, identity documents and every signed link depend on it. Make one with `php artisan key:generate --show`.';
        }

        if (config('app.debug')) {
            $problems['APP_DEBUG'] = 'is true. An error page would show whoever caused it the configuration, credentials included.';
        }

        if (blank(config('payments.stripe.secret_key'))) {
            $problems['STRIPE_SECRET_KEY'] = 'is empty. No card checkout can open, so nothing priced in CAD can be sold.';
        }

        if (blank(config('payments.stripe.webhook_secret'))) {
            $problems['STRIPE_WEBHOOK_SECRET'] = 'is empty. Every Stripe webhook is refused, so a buyer who pays is never marked paid and never gets a ticket.';
        }

        if (blank(config('payments.paystack.secret_key'))) {
            $problems['PAYSTACK_SECRET_KEY'] = 'is empty. No NGN checkout can open, and Paystack signs its webhooks with this key, so none of them would be believed.';
        }

        $problems += self::mail();
        $problems += self::texts();
        $problems += self::proxies();
        $problems += self::backups();

        return $problems;
    }

    /**
     * Stop here if production is not fit to serve.
     *
     * @throws PreflightFailed
     */
    public static function enforce(): void
    {
        $problems = self::problems();

        if ($problems !== []) {
            throw PreflightFailed::with($problems);
        }
    }

    /** @return array<string, string> */
    private static function mail(): array
    {
        $mailer = (string) config('mail.default');

        if (in_array($mailer, self::PRETEND_MAILERS, true)) {
            return ['MAIL_MAILER' => "is {$mailer}. Tickets, password resets and sign-up links would be reported as sent and delivered to nobody."];
        }

        return match ($mailer) {
            'smtp' => blank(config('mail.mailers.smtp.host')) && blank(config('mail.mailers.smtp.url'))
                ? ['MAIL_HOST' => 'is empty, so the SMTP mailer has nowhere to send anything.']
                : [],
            'zeptomail' => blank(config('mail.mailers.zeptomail.api_key'))
                ? ['ZEPTOMAIL_API_KEY' => 'is empty, so ZeptoMail refuses every message.']
                : [],
            default => [],
        };
    }

    /**
     * Texts are either really sent or not claimed at all.
     *
     * The log driver answers "accepted" for every message, which is right in
     * development and a lie in production: a buyer is told nothing while the
     * platform records that they were.
     *
     * Launching with no texts at all is honest, and allowed: SMS_COUNTRIES
     * empty means no number is ever worth texting, so nothing reaches the
     * driver and nothing is claimed, whichever driver is named.
     *
     * @return array<string, string>
     */
    private static function texts(): array
    {
        if (array_filter(array_map(trim(...), (array) config('sms.countries', []))) === []) {
            return [];
        }

        $driver = (string) config('sms.driver');

        $missing = match ($driver) {
            'termii' => array_filter(['TERMII_API_KEY' => config('sms.termii.key')], blank(...)),
            'twilio' => array_filter([
                'TWILIO_ACCOUNT_SID' => config('sms.twilio.sid'),
                'TWILIO_AUTH_TOKEN' => config('sms.twilio.token'),
                'TWILIO_FROM' => config('sms.twilio.from'),
            ], blank(...)),
            default => null,
        };

        if ($missing === null) {
            return ['SMS_DRIVER' => ($driver === 'log' ? 'is log' : "is \"{$driver}\", which is not a provider")
                .'. Every text would be reported as sent and none would arrive. Set termii or twilio, with its credentials — or empty SMS_COUNTRIES to send no texts at all.'];
        }

        $problems = array_map(fn () => "is empty, and SMS_DRIVER is {$driver}.", $missing);

        // STOP has to work before the first text goes, in both markets.
        if (blank(config('sms.inbound_secret'))) {
            $problems['SMS_INBOUND_SECRET'] = 'is empty, so a reply of STOP is refused and nobody can stop the texts.';
        }

        return $problems;
    }

    /**
     * Whoever terminates TLS, named.
     *
     * Empty means every request looks like it came from the load balancer,
     * so the per-address limits on signing in and signing up become one
     * limit shared by everybody — the sixth sign-up in an hour, anywhere,
     * is refused. A wildcard is worse: it believes any address a client
     * claims, which is how those limits were bypassed.
     *
     * @return array<string, string>
     */
    private static function proxies(): array
    {
        $proxies = (array) config('trustedproxy.proxies', []);

        if ($proxies === []) {
            return ['TRUSTED_PROXIES' => 'is empty. Name the load balancer\'s addresses, or every visitor shares one rate limit.'];
        }

        if (array_intersect($proxies, self::EVERYBODY) !== []) {
            return ['TRUSTED_PROXIES' => 'trusts every address, so anybody can claim to be anybody. Name the load balancer\'s addresses instead.'];
        }

        return [];
    }

    /**
     * Backups sealed before they leave the machine.
     *
     * The nightly backup runs in every production, with nothing to turn it
     * off: to the backups volume, or to a bucket when BACKUP_TARGET says so.
     * Without a key it stores the database as pg_dump wrote it — every
     * buyer's name, email and phone number, readable by whoever can read the
     * volume or the bucket. backup:run says so each night, in a log nobody
     * reads until something has already gone wrong.
     *
     * A key that is there and malformed is named too. backup:run refuses it
     * rather than store a plain dump, so every night would fail, and the
     * first anybody heard would be the restore that had nothing to restore.
     *
     * @return array<string, string>
     */
    private static function backups(): array
    {
        try {
            $key = BackupCipher::key(config('operations.backup.encryption_key'));
        } catch (RuntimeException) {
            return ['BACKUP_ENCRYPTION_KEY' => 'is not 32 bytes of base64, so every nightly backup would fail. Make one with `php artisan backup:key`.'];
        }

        return $key === null
            ? ['BACKUP_ENCRYPTION_KEY' => 'is empty, so every nightly backup would hold every buyer\'s details unencrypted. Make one with `php artisan backup:key`, and keep a copy away from this server and the backups.']
            : [];
    }
}
