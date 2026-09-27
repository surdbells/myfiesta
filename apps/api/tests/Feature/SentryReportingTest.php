<?php

namespace Tests\Feature;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Sentry\Breadcrumb;
use Sentry\ClientBuilder;
use Sentry\ClientInterface;
use Sentry\Event;
use Sentry\EventType;
use Sentry\Laravel\Http\SetRequestMiddleware;
use Sentry\Options;
use Sentry\SentrySdk;
use Sentry\Serializer\PayloadSerializer;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\TransactionContext;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;
use Tests\TestCase;

/**
 * Errors reach Sentry only when there is somewhere to send them, and only
 * after the scrubber has been over them.
 *
 * Tests and development run with no DSN, and then nothing may leave: no
 * request data gathered, no event sent. With one, every reported exception
 * goes — through before_send, which is where a buyer's details come out — and
 * every sampled trace goes through before_send_transaction, the same way.
 *
 * What is checked is what would go over the wire: the envelope the SDK would
 * send, not the event object on the way to it.
 */
class SentryReportingTest extends TestCase
{
    private const DSN = 'https://public@o1.ingest.sentry.io/1';

    /**
     * A buyer's name and street, for a query to fail on. Up here rather than
     * where the query is made because a report carries the source lines
     * around each frame, and those are code, not data: in the application
     * they never hold a value, and the test must not find its own.
     */
    private const BOUND = ['Ada Okafor', '12 Allen Avenue Ikeja'];

    public function test_nothing_is_set_up_to_send_without_a_dsn(): void
    {
        $this->assertNull(config('sentry.dsn'), 'Tests must not have a DSN: they would report to a real project.');

        $client = app(HubInterface::class)->getClient();

        $this->assertNotNull($client);
        $this->assertNull($client->getOptions()->getDsn());

        // The middleware that gathers request data for an event is only added
        // when there is somewhere to send one.
        $this->assertFalse(app(Kernel::class)->hasMiddleware(SetRequestMiddleware::class));
    }

    public function test_personal_details_are_off_whatever_the_environment_says(): void
    {
        $this->assertFalse(config('sentry.send_default_pii'));
        $this->assertFalse(config('sentry.breadcrumbs.sql_bindings'));
        $this->assertFalse(config('sentry.tracing.sql_bindings'));

        $options = app(HubInterface::class)->getClient()->getOptions();

        $this->assertFalse($options->shouldSendDefaultPii());
        $this->assertSame('never', $options->getMaxRequestBodySize(), 'A request body is where every personal detail arrives.');
    }

    public function test_a_reported_exception_goes_to_sentry_scrubbed(): void
    {
        $transport = $this->transport();
        SentrySdk::getCurrentHub()->bindClient($this->client($transport));

        app(ExceptionHandler::class)->report(new RuntimeException(
            'Payout to 0123456789 for ada@example.com failed',
        ));

        $this->assertCount(1, $transport->sent, 'The exception hook in bootstrap/app.php did not report to Sentry.');

        $message = $transport->sent[0]->getExceptions()[0]->getValue();

        $this->assertStringNotContainsString('0123456789', $message);
        $this->assertStringNotContainsString('ada@example.com', $message);
        $this->assertStringContainsString('Payout to', $message);
    }

    public function test_a_performance_trace_goes_to_sentry_scrubbed(): void
    {
        $transport = $this->transport();
        $hub = SentrySdk::getCurrentHub();
        $hub->bindClient($this->client($transport, ['traces_sample_rate' => 1.0]));

        $hub->withScope(function (Scope $scope) use ($hub): void {
            // What the request integration attaches to every event from a
            // request, traces included. Stood in for, as a test makes no real
            // request — body and all, which production never attaches, so the
            // scrubber is shown to hold even if that ever changes.
            $scope->addEventProcessor(fn (Event $event) => $event->setRequest([
                'url' => 'https://api.myfiesta.ca/api/door-passes/Dp_s3cr3tPass/claim?token=Tk_abc123',
                'method' => 'POST',
                'query_string' => 'token=Tk_abc123',
                'headers' => ['cf-connecting-ip' => ['203.0.113.5'], 'accept' => ['application/json']],
                'data' => ['email' => 'ada@example.com', 'password' => 'hunter2', 'account_number' => '0123456789'],
            ]));

            // The rate limiter's key, as the cache integration records it.
            $hub->addBreadcrumb(new Breadcrumb('info', 'default', 'cache', 'Missed: login:ada@example.com|203.0.113.5'));

            // As sentry-laravel's tracing middleware starts it: named after
            // the route once one matches, and holding the path it was made to.
            $transaction = $hub->startTransaction(TransactionContext::make()
                ->setName('/api/door-passes/{secret}/claim')
                ->setOp('http.server')
                ->setData(['url' => '/api/door-passes/Dp_s3cr3tPass/claim', 'http.request.method' => 'POST']));
            $hub->setSpan($transaction);

            $transaction->startChild(SpanContext::make()
                ->setOp('http.client')
                ->setDescription('GET https://api.paystack.co/customer/ada@example.com')
                ->setData([
                    'url' => 'https://api.paystack.co/customer/ada@example.com',
                    'http.query' => 'email=ada%40example.com&token=Tk_abc123&perPage=5',
                    'http.fragment' => 'Fr4gment',
                ]))->finish();

            $transaction->startChild(SpanContext::make()
                ->setOp('cache.get')
                ->setDescription('login:ada@example.com|203.0.113.5')
                ->setData(['cache.key' => ['login:ada@example.com|203.0.113.5']]))->finish();

            $transaction->finish();
        });

        $this->assertCount(1, $transport->sent, 'No trace was sent, so this proves nothing.');

        $event = $transport->sent[0];
        $this->assertEquals(EventType::transaction(), $event->getType());

        $payload = $this->payload($event);

        foreach (['s3cr3tPass', 'Tk_abc123', 'ada@example.com', 'ada%40example.com', 'hunter2', '0123456789', '203.0.113.5', 'Fr4gment'] as $leak) {
            $this->assertStringNotContainsString($leak, $payload, "A trace sent {$leak}.");
        }

        // What it was about survives: which route, where the time went, and the
        // ids that join its spans together.
        $trace = $event->getContexts()['trace'];
        $this->assertSame('/api/door-passes/{secret}/claim', $event->getTransaction());
        $this->assertSame('/api/door-passes/[redacted]/claim', $trace['data']['url']);
        $this->assertSame((string) $event->getSpans()[0]->getTraceId(), $trace['trace_id']);
        $this->assertSame('https://api.paystack.co/customer/[redacted]', $event->getSpans()[0]->getData()['url']);
        $this->assertStringContainsString('perPage=5', $event->getSpans()[0]->getData()['http.query']);
        $this->assertSame('login:[redacted]|[redacted]', $event->getSpans()[1]->getDescription());
    }

    public function test_a_failed_query_reaches_sentry_without_the_values_it_was_given(): void
    {
        $transport = $this->transport();
        SentrySdk::getCurrentHub()->bindClient($this->client($transport));

        // A real failure from the real database: Postgres quotes the value it
        // could not read back in its message, Laravel substitutes every value
        // into the statement it quotes, and each frame on the way holds them.
        try {
            DB::select('select cast(? as integer) as quantity, ? as holder', self::BOUND);
            $this->fail('The query was meant to fail.');
        } catch (QueryException $e) {
            app(ExceptionHandler::class)->report($e);
        }

        $this->assertCount(1, $transport->sent);
        $this->assertStringContainsString(self::BOUND[0], $e->getMessage(), 'Postgres no longer repeats the value, so this proves less.');

        $event = $transport->sent[0];
        $payload = $this->payload($event);

        foreach ([...self::BOUND, 'Host: '.config('database.connections.pgsql.host')] as $leak) {
            $this->assertStringNotContainsString($leak, $payload, "A query error sent {$leak}.");
        }

        // The statement as it was written is kept: which query failed is most
        // of what makes the report worth reading.
        $value = $event->getExceptions()[0]->getValue();
        $this->assertStringContainsString('invalid input syntax for type integer', $value);
        $this->assertStringEndsWith('SQL: select cast(? as integer) as quantity, ? as holder)', $value);

        if (! ini_get('zend.exception_ignore_args')) {
            $vars = collect($event->getExceptions())
                ->flatMap(fn ($exception) => $exception->getStacktrace()?->getFrames() ?? [])
                ->flatMap(fn ($frame) => $frame->getVars());

            // Arguments were captured here, so the frames did hold the values
            // and it was the scrubber that took them out.
            $this->assertSame('[redacted]', $vars->get('bindings'));
        }
    }

    /** @param  array<string, mixed>  $options */
    private function client(TransportInterface $transport, array $options = []): ClientInterface
    {
        // A client with a DSN, sending nowhere but into this test, and set up
        // the way config/sentry.php sets up the real one.
        return ClientBuilder::create([
            'dsn' => self::DSN,
            'before_send' => config('sentry.before_send'),
            'before_send_transaction' => config('sentry.before_send_transaction'),
            'send_default_pii' => config('sentry.send_default_pii'),
            'max_request_body_size' => config('sentry.max_request_body_size'),
            ...$options,
        ])->setTransport($transport)->getClient();
    }

    /** The envelope as it would be sent. */
    private function payload(Event $event): string
    {
        return (new PayloadSerializer(new Options(['dsn' => self::DSN])))->serialize($event);
    }

    /** @return TransportInterface&object{sent: list<Event>} */
    private function transport(): TransportInterface
    {
        return new class implements TransportInterface
        {
            /** @var list<Event> */
            public array $sent = [];

            public function send(Event $event): Result
            {
                $this->sent[] = $event;

                return new Result(ResultStatus::success(), $event);
            }

            public function close(?int $timeout = null): Result
            {
                return new Result(ResultStatus::success());
            }
        };
    }
}
