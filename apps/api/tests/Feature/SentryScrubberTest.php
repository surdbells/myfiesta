<?php

namespace Tests\Feature;

use App\Support\Observability\SentryScrubber;
use Sentry\Event;
use Tests\TestCase;

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
}
