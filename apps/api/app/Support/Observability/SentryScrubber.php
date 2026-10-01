<?php

namespace App\Support\Observability;

use Illuminate\Database\QueryException;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\Tracing\Span;
use Sentry\UserDataBag;
use Throwable;

/**
 * Last check before an error or a performance trace leaves the process.
 *
 * send_default_pii is off, which stops Sentry attaching user details on
 * purpose. It does not stop an exception carrying them by accident: a stack
 * trace captures local variables, and the frames around identity review or a
 * payout form hold decrypted bank details and government identifiers. Those are
 * exactly the values that must never reach a third-party service.
 *
 * So this runs on every event — errors through before_send, traces through
 * before_send_transaction — and removes anything whose name suggests it is
 * sensitive, wherever it appears. It errs towards over-redaction — a redacted
 * field costs a little debugging context, a leaked one costs considerably more.
 *
 * Names are not the only way in, so text is read as well. A database error
 * repeats the values it was given — "Key (email)=(ada@example.com) already
 * exists", and the whole statement with every value substituted in — and names
 * the host it could not reach; an address in a URL can be the whole credential
 * (a ticket link, an unsubscribe link, a door pass); a rate limiter's cache key
 * is an email and an IP address. Those are taken out of exception messages, the
 * event's message, breadcrumbs, a trace's spans and the request's address and
 * query string too.
 */
final class SentryScrubber
{
    /**
     * Matched case-insensitively as substrings, so `bank_account_number` and
     * `accountNumber` are both caught without listing every spelling.
     */
    private const SENSITIVE = [
        'password', 'secret', 'token', 'authorization', 'api_key', 'apikey',
        'account_number', 'accountnumber', 'transit', 'institution', 'bank',
        'interac', 'iban', 'sort_code', 'card', 'cvv', 'cvc', 'pan',
        'document_number', 'documentnumber', 'date_of_birth', 'dob',
        'legal_first_name', 'legal_last_name', 'national_id', 'passport',
        'signature', 'webhook_secret', 'private_key',
        // A session cookie is a signed-in person; a CSRF token is half of one.
        'cookie', 'session', 'xsrf', 'csrf',
        // The emailed sign-in code, the handoff that opens a staff session,
        // and the code on a ticket, which is what gets somebody in the door.
        'otp', 'mfa', 'handoff', 'credential', 'ticket_code', 'qr',
        // Who somebody is and how to reach them.
        'email', 'phone', 'buyer_name', 'holder_name', 'attendee_name',
        'first_name', 'last_name', 'full_name', 'legal_name', 'street', 'postal',
        'address',
        'dsn',
        // The values a query was given: an argument called $bindings in every
        // frame between the model and the database.
        'binding',
    ];

    /**
     * Matched only as the whole name: short enough that as a substring they
     * would take out half the payload (`status_code`, `monkey`), and each the
     * name something secret goes by here — a ticket's `code`, the `hash` in
     * an address-proving link, a door `pin`, the `name` on a ticket or a sign-up.
     */
    private const SENSITIVE_EXACTLY = ['code', 'codes', 'pin', 'hash', 'key', 'name'];

    /**
     * Paths whose next segments are a credential, and how many of them: the
     * link itself is what lets somebody in, so the address is as secret as a
     * password. An address-proving link is the account and then a hash of the
     * address it is proving.
     *
     * tests/Feature/SentryScrubberTest walks the routes and fails when one
     * takes a credential this does not cover.
     */
    private const SECRET_AFTER = [
        'tickets' => 1, 'invitations' => 1, 'door-passes' => 1, 'unsubscribe' => 1,
        'waitlist' => 1, 'follows' => 1, 'requests' => 1, 'sign-up' => 1, 'sms' => 1,
        'reset-password' => 1, 'verify-email' => 2, 'surveys' => 1,
    ];

    /** Data names that hold an address, in breadcrumbs, spans and a trace. */
    private const ADDRESSES = ['url', 'from', 'to', 'http.url', 'url.full'];

    private const REDACTED = '[redacted]';

    /**
     * @param  list<string>  $bound  what a failed query was given, longest first
     */
    private function __construct(private readonly array $bound = []) {}

    /**
     * Static so the config entry stays a plain array callable.
     *
     * A closure here would work and would also break `config:cache`, which is
     * the difference between a fast boot in production and a fatal error.
     */
    public static function handle(Event $event, ?EventHint $hint): ?Event
    {
        $thrown = self::chain($hint?->exception);
        $scrubber = new self(self::boundValues($thrown));

        if ($request = $event->getRequest()) {
            $event->setRequest($scrubber->request($request));
        }

        $event->setExtra($scrubber->scrub($event->getExtra()));

        foreach ($event->getContexts() as $name => $context) {
            if (is_array($context)) {
                $event->setContext((string) $name, $name === 'trace' ? $scrubber->trace($context) : $scrubber->scrub($context));
            }
        }

        // Tags are indexed and searchable in Sentry, so a sensitive value here
        // is worse than one buried in a payload.
        $event->setTags($scrubber->tags($event->getTags()));

        if ($event->getMessage() !== null) {
            $event->setMessage(
                $scrubber->text($event->getMessage()),
                array_map(fn ($param) => is_string($param) ? $scrubber->text($param) : $param, $event->getMessageParams()),
                $event->getMessageFormatted() === null ? null : $scrubber->text($event->getMessageFormatted()),
            );
        }

        // Built from the thrown exception and its previous ones, in that order,
        // so the same position in both is the same exception.
        foreach ($event->getExceptions() as $i => $exception) {
            $value = $scrubber->text($exception->getValue());
            $cause = $thrown[$i] ?? null;

            if ($cause instanceof QueryException && $exception->getType() === $cause::class) {
                $value = $scrubber->withStatement($value, $cause->getSql());
            }

            $exception->setValue($value);

            foreach ($exception->getStacktrace()?->getFrames() ?? [] as $frame) {
                $frame->setVars($scrubber->scrub($frame->getVars()));
            }
        }

        $event->setBreadcrumb(array_map($scrubber->breadcrumb(...), $event->getBreadcrumbs()));

        // Only a trace has spans: every query, cache read and outgoing request
        // the traced request made, described by its statement, key or address.
        foreach ($event->getSpans() as $span) {
            $scrubber->span($span);
        }

        // Which account, if anything set one — never who they are.
        if ($user = $event->getUser()) {
            $event->setUser($user->getId() === null ? null : UserDataBag::createFromUserIdentifier($user->getId()));
        }

        return $event;
    }

    /** @return list<Throwable> */
    private static function chain(?Throwable $thrown): array
    {
        $chain = [];

        for (; $thrown !== null && count($chain) < 32; $thrown = $thrown->getPrevious()) {
            $chain[] = $thrown;
        }

        return $chain;
    }

    /**
     * What any failed query in the chain was given.
     *
     * Its values are what the message repeats, what Postgres quotes back ("invalid
     * input syntax for type integer: …") and what every frame between the model
     * and the driver holds as an argument — under names nobody would list. So
     * they are taken out by value, wherever they turn up.
     *
     * @param  list<Throwable>  $chain
     * @return list<string>
     */
    private static function boundValues(array $chain): array
    {
        $bound = [];

        foreach ($chain as $thrown) {
            if ($thrown instanceof QueryException) {
                foreach ($thrown->getBindings() as $value) {
                    if (is_string($value) && $value !== '') {
                        $bound[] = $value;
                    }
                }
            }
        }

        $bound = array_values(array_unique($bound));

        // Longest first, so a value that contains a shorter one goes whole.
        usort($bound, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        return $bound;
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    private function request(array $request): array
    {
        $request = $this->scrub($request);

        if (is_string($request['url'] ?? null)) {
            $request['url'] = $this->url($request['url']);
        }

        if (is_string($request['query_string'] ?? null) && $request['query_string'] !== '') {
            $request['query_string'] = $this->query($request['query_string']);
        }

        return $request;
    }

    private function breadcrumb(Breadcrumb $breadcrumb): Breadcrumb
    {
        if ($breadcrumb->getMessage() !== null) {
            $breadcrumb = $breadcrumb->withMessage($this->text($breadcrumb->getMessage()));
        }

        foreach ($this->data($breadcrumb->getMetadata()) as $name => $value) {
            $breadcrumb = $breadcrumb->withMetadata((string) $name, $value);
        }

        return $breadcrumb;
    }

    private function span(Span $span): void
    {
        if ($span->getDescription() !== null) {
            $span->setDescription($this->text($span->getDescription()));
        }

        // Both merge into what the span has, and every name comes back, so
        // every value is replaced.
        $span->setData($this->data($span->getData()));
        $span->setTags($this->tags($span->getTags()));
    }

    /**
     * The trace context: its ids are what joins an error to its request and
     * a span to its trace, carry nothing and must reach Sentry as they are.
     * What it says about the request — the path it was made to — is read
     * like any span.
     *
     * @param  array<string, mixed>  $trace
     * @return array<string, mixed>
     */
    private function trace(array $trace): array
    {
        if (is_array($trace['data'] ?? null)) {
            $trace['data'] = $this->data($trace['data']);
        }

        if (is_string($trace['description'] ?? null)) {
            $trace['description'] = $this->text($trace['description']);
        }

        if (is_array($trace['tags'] ?? null)) {
            $trace['tags'] = $this->tags($trace['tags']);
        }

        return $trace;
    }

    /**
     * Data on a breadcrumb or a span: values under names, and the names that
     * hold an address, a query string or a fragment read as what they are.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function data(array $data): array
    {
        $data = $this->scrub($data);

        foreach ($data as $name => $value) {
            if (! is_string($value) || $value === '') {
                continue;
            }

            if (in_array($name, self::ADDRESSES, true)) {
                $data[$name] = $this->url($value);
            } elseif ($name === 'http.query') {
                $data[$name] = $this->query($value);
            } elseif ($name === 'http.fragment') {
                $data[$name] = self::REDACTED;
            }
        }

        return $data;
    }

    /**
     * @param  array<array-key, mixed>  $tags
     * @return array<array-key, mixed>
     */
    private function tags(array $tags): array
    {
        foreach ($tags as $name => $value) {
            if ($this->isSensitive((string) $name)) {
                $tags[$name] = self::REDACTED;
            }
        }

        return $tags;
    }

    private function scrub(array $data): array
    {
        foreach ($data as $key => $value) {
            if ($this->isSensitive((string) $key)) {
                $data[$key] = self::REDACTED;

                continue;
            }

            if (is_array($value)) {
                $data[$key] = $this->scrub($value);
            } elseif (is_string($value)) {
                $data[$key] = in_array($value, $this->bound, true) ? self::REDACTED : $this->text($value);
            }
        }

        return $data;
    }

    private function isSensitive(string $key): bool
    {
        $key = strtolower($key);

        if (in_array($key, self::SENSITIVE_EXACTLY, true)) {
            return true;
        }

        foreach (self::SENSITIVE as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Values that give themselves away whatever they are called.
     *
     * The values a failed query was given; an email address; an IP address;
     * seven or more digits in a row, a phone number, or four groups of four,
     * which are the shapes of a bank account, a phone and a card; and what a
     * database error repeats back — the values in its DETAIL line, the host it
     * was connected to, and the statement it quotes.
     */
    private function text(string $text): string
    {
        // The statement a query error quotes has every value substituted in,
        // bare — "values (Ada Okafor, 12 Allen Avenue)" — so there is nothing
        // to tell a value from the SQL around it. It goes whole; handle() puts
        // it back with its placeholders when it has the query itself.
        $text = preg_replace('/\bSQL: .*$/s', 'SQL: '.self::REDACTED, $text) ?? $text;

        // Only values three characters or longer: a shorter one taken out of
        // running text takes letters out of the words around it too.
        $bound = array_filter($this->bound, fn (string $value) => strlen($value) >= 3);

        if ($bound !== []) {
            $text = str_replace($bound, self::REDACTED, $text);
        }

        // Addresses first, so a token in one is taken out whole.
        $text = preg_replace_callback('#https?://[^\s"\'<>()]+#i', fn (array $url) => $this->url($url[0]), $text) ?? $text;

        $text = preg_replace([
            '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i',
            // IP addresses: who somebody is as far as a rate limiter is
            // concerned, so its keys carry them and a cache breadcrumb repeats
            // the key. IPv4 before IPv6, so a mapped address goes whole; and
            // IPv6 only in full or with a "::" and a digit, so a time of day
            // and `Feed::add` both stay.
            '/(?<![\w.])(?:\d{1,3}\.){3}\d{1,3}(?!\w|\.\d)/',
            '/(?<![\w:])(?:[0-9a-f]{1,4}:){7}[0-9a-f]{1,4}(?![\w:])/i',
            '/(?<![\w:])(?=[0-9a-f:]*\d)(?:[0-9a-f]{1,4}(?::[0-9a-f]{1,4})*)?::(?:[0-9a-f]{1,4}(?::[0-9a-f]{1,4})*)?(?![\w:])/i',
            '/(?<![\w.\-])\d{7,}(?![\w.\-])/',
            '/\+\d[\d \-]{6,}\d/',
            '/\b(?:\d{4}[ \-]){3}\d{1,4}\b/',
        ], self::REDACTED, $text) ?? $text;

        return preg_replace(
            [
                '/(Key \([^)]*\))=\((?:[^()]|\([^()]*\))*\)/',
                '/\b(Host|Port): [^,)\s]+/',
                '/\b(host name|server at|host) "[^"]*"/i',
            ],
            ['$1=('.self::REDACTED.')', '$1: '.self::REDACTED, '$1 "'.self::REDACTED.'"'],
            $text,
        ) ?? $text;
    }

    /**
     * A query error's message with its statement back, as it was written:
     * placeholders where the values went, and any literal in it replaced too.
     * Which table and which statement is most of what makes a deadlock or a
     * constraint failure worth reading, and none of it is anybody's.
     */
    private function withStatement(string $value, string $sql): string
    {
        $cut = 'SQL: '.self::REDACTED;

        if (! str_ends_with($value, $cut)) {
            return $value;
        }

        $statement = $this->text(preg_replace("/'(?:[^']|'')*'/", "'?'", $sql) ?? $sql);

        return substr($value, 0, -strlen($cut)).'SQL: '.$statement.')';
    }

    /** A query string with the values under sensitive names taken out. */
    private function query(string $query): string
    {
        parse_str($query, $values);

        return http_build_query($this->scrub($values));
    }

    /** The address without the parts of it that are credentials. */
    private function url(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false) {
            return self::REDACTED;
        }

        $segments = explode('/', $parts['path'] ?? '');
        $secret = 0;

        foreach ($segments as $i => $segment) {
            if ($secret > 0 && $segment !== '') {
                $segments[$i] = self::REDACTED;
                $secret--;

                continue;
            }

            $secret = self::SECRET_AFTER[strtolower($segment)] ?? 0;
        }

        return (isset($parts['scheme']) ? $parts['scheme'].'://' : '')
            .($parts['host'] ?? '')
            .(isset($parts['port']) ? ':'.$parts['port'] : '')
            .implode('/', $segments)
            .(isset($parts['query']) ? '?'.$this->query($parts['query']) : '');
    }
}
