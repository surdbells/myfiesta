<?php

namespace Tests\Feature;

use App\Support\Observability\SentryScrubber;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Route;
use PDOException;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\ExceptionDataBag;
use Sentry\Frame;
use Sentry\Stacktrace;
use Sentry\UserDataBag;
use Tests\TestCase;
use Throwable;

/**
 * The scrubber, exercised rather than trusted.
 *
 * A redaction list that silently stops matching is worse than none, because it
 * reads like protection.
 */
class SentryScrubberTest extends TestCase
{
    public function test_the_config_entry_is_actually_callable(): void
    {
        // An uncallable before_send is accepted by config and does nothing at
        // runtime, so the protection would be imaginary. Instance methods and
        // closures both fail here for different reasons.
        $this->assertIsCallable(config('sentry.before_send'));

        // Traces never pass before_send. Without their own entry they go out
        // exactly as the SDK built them.
        $this->assertIsCallable(config('sentry.before_send_transaction'));
        $this->assertSame(config('sentry.before_send'), config('sentry.before_send_transaction'));
    }

    public function test_banking_and_identity_fields_are_redacted(): void
    {
        $event = Event::createEvent();
        $event->setExtra([
            'bank_account_number' => '000123456789',
            'transit_number' => '12345',
            'document_number' => 'AB1234567',
            'date_of_birth' => '1990-01-01',
            'interac_email' => 'payouts@example.com',
            'event_title' => 'Afro Fest',
        ]);

        $extra = SentryScrubber::handle($event, null)->getExtra();

        $this->assertSame('[redacted]', $extra['bank_account_number']);
        $this->assertSame('[redacted]', $extra['transit_number']);
        $this->assertSame('[redacted]', $extra['document_number']);
        $this->assertSame('[redacted]', $extra['date_of_birth']);
        $this->assertSame('[redacted]', $extra['interac_email']);

        // Over-redaction has a cost too. Ordinary context must survive or the
        // error report stops being useful.
        $this->assertSame('Afro Fest', $extra['event_title']);
    }

    public function test_redaction_reaches_nested_payloads(): void
    {
        $event = Event::createEvent();
        $event->setExtra([
            'order' => [
                'reference' => 'ABC123',
                'payment' => ['card_number' => '4242424242424242'],
            ],
        ]);

        $extra = SentryScrubber::handle($event, null)->getExtra();

        $this->assertSame('[redacted]', $extra['order']['payment']['card_number']);
        $this->assertSame('ABC123', $extra['order']['reference']);
    }

    public function test_camel_case_and_partial_names_are_caught(): void
    {
        $event = Event::createEvent();
        $event->setExtra([
            'accountNumber' => '999',
            'apiKey' => 'secret-value',
            'stripeWebhookSecret' => 'whsec_x',
        ]);

        $extra = SentryScrubber::handle($event, null)->getExtra();

        // Matching is substring and case-insensitive precisely so that a new
        // spelling does not quietly slip through.
        $this->assertSame('[redacted]', $extra['accountNumber']);
        $this->assertSame('[redacted]', $extra['apiKey']);
        $this->assertSame('[redacted]', $extra['stripeWebhookSecret']);
    }

    public function test_the_request_loses_its_credentials_and_keeps_its_shape(): void
    {
        $event = Event::createEvent();
        $event->setRequest([
            'url' => 'https://api.myfiesta.ca/api/tickets/Tk_8f3kQ2mZr9LwX1vB/resale/42',
            'method' => 'POST',
            'query_string' => 'token=abc123&page=2&email=ada%40example.com',
            'headers' => [
                'authorization' => ['Bearer 12|secretsecretsecret'],
                'cookie' => ['laravel_session=abc; XSRF-TOKEN=def'],
                'x-xsrf-token' => ['def'],
                'accept' => ['application/json'],
            ],
            'cookies' => ['laravel_session' => 'abc'],
            'data' => ['code' => 'ABCD-12345678', 'password' => 'hunter2', 'quantity' => 2],
        ]);

        $request = SentryScrubber::handle($event, null)->getRequest();

        $this->assertSame('https://api.myfiesta.ca/api/tickets/[redacted]/resale/42', $request['url']);
        $this->assertSame('[redacted]', $request['headers']['authorization']);
        $this->assertSame('[redacted]', $request['headers']['cookie']);
        $this->assertSame('[redacted]', $request['headers']['x-xsrf-token']);
        $this->assertSame('[redacted]', $request['cookies']);
        $this->assertSame('[redacted]', $request['data']['code'], 'A ticket code is what gets somebody in the door.');
        $this->assertSame('[redacted]', $request['data']['password']);
        $this->assertSame(2, $request['data']['quantity']);
        $this->assertSame(['application/json'], $request['headers']['accept']);

        parse_str($request['query_string'], $query);
        $this->assertSame(['token' => '[redacted]', 'page' => '2', 'email' => '[redacted]'], $query);
    }

    public function test_a_database_error_does_not_repeat_the_values_or_the_host(): void
    {
        // Built the way the framework builds one, so the message is the one
        // Laravel writes: every value substituted into the statement bare.
        $query = new QueryException(
            'pgsql',
            'insert into "organization_payout_details" ("email", "account_number", "holder") values (?, ?, ?)',
            ['ada@example.com', '0123456789', 'Ada Okafor'],
            new PDOException(
                "SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint \"users_email_unique\"\n"
                .'DETAIL:  Key (email)=(ada@example.com) already exists.',
            ),
            ['driver' => 'pgsql', 'host' => 'db-primary.internal', 'port' => 5432, 'database' => 'myfiesta'],
        );

        $value = $this->reported($query)->getExceptions()[0]->getValue();

        foreach (['ada@example.com', 'db-primary.internal', '0123456789', 'Ada Okafor'] as $leak) {
            $this->assertStringNotContainsString($leak, $value);
        }

        // What it was about survives: which constraint, which table, which
        // statement — with its placeholders where the values were.
        $this->assertStringContainsString('users_email_unique', $value);
        $this->assertStringEndsWith(
            'SQL: insert into "organization_payout_details" ("email", "account_number", "holder") values (?, ?, ?))',
            $value,
        );
    }

    public function test_a_query_error_sends_none_of_the_values_it_was_given(): void
    {
        // Names, a street and a letter-led ID number: nothing in their shape
        // gives them away, and Laravel does not quote them.
        $query = new QueryException(
            'pgsql',
            'insert into "orders" ("buyer_name", "buyer_street", "passport") values (?, ?, ?)',
            ['Ada Okafor', '12 Allen Avenue Ikeja', 'A12345678'],
            new PDOException('SQLSTATE[40P01]: Deadlock detected: 7 ERROR:  deadlock detected'),
            ['driver' => 'pgsql', 'host' => '10.20.0.5', 'port' => 5432, 'database' => 'myfiesta'],
        );

        $frame = new Frame('runQueryCallback', __FILE__, 1);
        $frame->setVars(['query' => $query->getSql(), 'parameters' => ['Ada Okafor', '12 Allen Avenue Ikeja', 'A12345678']]);

        $event = $this->reported($query, new Stacktrace([$frame]));
        $value = $event->getExceptions()[0]->getValue();

        foreach (['Ada Okafor', '12 Allen Avenue Ikeja', 'A12345678', '10.20.0.5'] as $leak) {
            $this->assertStringNotContainsString($leak, $value);
        }

        $this->assertStringStartsWith('SQLSTATE[40P01]: Deadlock detected', $value);
        $this->assertStringContainsString('values (?, ?, ?))', $value);

        // A frame's arguments hold the values under whatever name that frame
        // gave them; they go by value.
        $vars = $event->getExceptions()[0]->getStacktrace()->getFrames()[0]->getVars();
        $this->assertSame(['[redacted]', '[redacted]', '[redacted]'], $vars['parameters']);
        $this->assertSame($query->getSql(), $vars['query']);

        // Without the exception to read the statement from — the same message
        // repeated in a log line, say — the statement goes whole.
        $log = Event::createEvent();
        $log->setBreadcrumb([new Breadcrumb('error', 'default', 'log', $query->getMessage())]);

        $message = SentryScrubber::handle($log, null)->getBreadcrumbs()[0]->getMessage();

        $this->assertStringEndsWith('SQL: [redacted]', $message);
        $this->assertStringNotContainsString('Ada Okafor', $message);
    }

    public function test_an_ip_address_is_taken_out_of_a_rate_limiter_key(): void
    {
        // The keys the sign-in, sign-up and forgotten-password limits use, as
        // the cache integration records every read and write of them.
        $event = Event::createEvent();
        $event->setBreadcrumb([
            new Breadcrumb('info', 'default', 'cache', 'Missed: login:ada@example.com|203.0.113.5'),
            new Breadcrumb('info', 'default', 'cache', 'Written: register:198.51.100.7:timer'),
            new Breadcrumb('info', 'default', 'cache', 'Read: forgot:ada@example.com|2001:db8::8a2e:370:7334'),
            new Breadcrumb('info', 'default', 'cache', 'Read: login:ada@example.com|2001:0db8:85a3:0000:0000:8a2e:0370:7334'),
        ]);
        $event->setExtra(['at' => '06:30:00', 'call' => 'App\Models\Feed::add', 'php' => '8.3.12']);

        $scrubbed = SentryScrubber::handle($event, null);
        $messages = array_map(fn (Breadcrumb $crumb) => $crumb->getMessage(), $scrubbed->getBreadcrumbs());

        $this->assertSame([
            'Missed: login:[redacted]|[redacted]',
            'Written: register:[redacted]:timer',
            'Read: forgot:[redacted]|[redacted]',
            'Read: login:[redacted]|[redacted]',
        ], $messages);

        // A time of day, a static call and a version are not addresses.
        $this->assertSame(['at' => '06:30:00', 'call' => 'App\Models\Feed::add', 'php' => '8.3.12'], $scrubbed->getExtra());
    }

    public function test_no_route_sends_the_credential_in_its_address(): void
    {
        // Parameters that are the credential, or somebody's details: a link's
        // token or secret, a pending sign-up, the hash of an address.
        $credentials = ['token', 'secret', 'hash', 'registration'];
        $checked = [];

        foreach (Route::getRoutes() as $route) {
            $taken = array_intersect($route->parameterNames(), $credentials);

            if ($taken === []) {
                continue;
            }

            $path = preg_replace_callback(
                '/\{(\w+)(?::\w+)?\??\}/',
                fn (array $parameter) => in_array($parameter[1], $credentials, true) ? 'Cr3dential'.$parameter[1] : '42',
                $route->uri(),
            );

            $event = Event::createEvent();
            $event->setRequest(['url' => 'https://api.myfiesta.ca/'.$path, 'method' => 'GET']);

            $this->assertStringNotContainsString(
                'Cr3dential',
                SentryScrubber::handle($event, null)->getRequest()['url'],
                "/{$route->uri()} sends its {".implode('}, {', $taken).'} to Sentry: add its path to SentryScrubber::SECRET_AFTER.',
            );

            $checked[] = $route->uri();
        }

        // The walk found the links it is here for.
        $this->assertContains('api/door-passes/{secret}/claim', $checked);
        $this->assertContains('verify-email/{user}/{hash}', $checked);
    }

    public function test_a_connection_failure_does_not_name_the_server(): void
    {
        $event = Event::createEvent();
        $event->setExceptions([new ExceptionDataBag(new \RuntimeException(
            'SQLSTATE[08006] [7] connection to server at "10.20.0.5", port 5432 failed: Connection refused',
        ))]);

        $value = SentryScrubber::handle($event, null)->getExceptions()[0]->getValue();

        $this->assertStringNotContainsString('10.20.0.5', $value);
        $this->assertStringContainsString('Connection refused', $value);
    }

    public function test_stack_frame_arguments_and_breadcrumbs_are_scrubbed(): void
    {
        $frame = new Frame('verify', __FILE__, 1);
        $frame->setVars(['accountNumber' => '0123456789', 'event' => 'Afro Fest']);

        $event = Event::createEvent();
        $event->setExceptions([(new ExceptionDataBag(new \RuntimeException('x')))->setStacktrace(new Stacktrace([$frame]))]);
        $event->setBreadcrumb([
            new Breadcrumb('info', 'http', 'http', 'GET https://myfiesta.ca/unsubscribe/abc123def456', [
                'url' => 'https://api.myfiesta.ca/api/door-passes/s3cr3t-pass/claim',
                'status_code' => 200,
            ]),
        ]);
        $event->setUser(UserDataBag::createFromArray(['id' => 'u-1', 'email' => 'ada@example.com', 'ip_address' => '203.0.113.9']));

        $scrubbed = SentryScrubber::handle($event, null);

        $vars = $scrubbed->getExceptions()[0]->getStacktrace()->getFrames()[0]->getVars();
        $this->assertSame('[redacted]', $vars['accountNumber']);
        $this->assertSame('Afro Fest', $vars['event']);

        $crumb = $scrubbed->getBreadcrumbs()[0];
        $this->assertSame('https://api.myfiesta.ca/api/door-passes/[redacted]/claim', $crumb->getMetadata()['url']);
        $this->assertSame('GET https://myfiesta.ca/unsubscribe/[redacted]', $crumb->getMessage());
        $this->assertSame(200, $crumb->getMetadata()['status_code']);

        // Which account, never who they are.
        $this->assertSame('u-1', $scrubbed->getUser()->getId());
        $this->assertNull($scrubbed->getUser()->getEmail());
        $this->assertNull($scrubbed->getUser()->getIpAddress());
    }

    public function test_sensitive_tags_are_redacted(): void
    {
        $event = Event::createEvent();
        $event->setTags(['organization' => 'Lagos Nights', 'api_key' => 'abc123']);

        $tags = SentryScrubber::handle($event, null)->getTags();

        // Tags are indexed and searchable, so a leak here is worse than one
        // buried in a payload.
        $this->assertSame('[redacted]', $tags['api_key']);
        $this->assertSame('Lagos Nights', $tags['organization']);
    }

    /**
     * An exception scrubbed as the SDK hands it over: one entry for it and
     * each previous one, in that order, and the exception itself in the hint.
     */
    private function reported(Throwable $thrown, ?Stacktrace $stacktrace = null): Event
    {
        $exceptions = [];

        for ($cause = $thrown; $cause !== null; $cause = $cause->getPrevious()) {
            $exceptions[] = new ExceptionDataBag($cause, $cause === $thrown ? $stacktrace : null);
        }

        $event = Event::createEvent();
        $event->setExceptions($exceptions);

        return SentryScrubber::handle($event, EventHint::fromArray(['exception' => $thrown]));
    }
}
