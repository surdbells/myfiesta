<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The operator's details, as the public site's legal pages read them.
 *
 * They are configuration because none of them is ours to write. What matters
 * here is the honesty: missing details are reported as missing, so the pages
 * can say so instead of showing an address nobody reads.
 */
class ContactDetailsTest extends TestCase
{
    public function test_says_it_is_incomplete_until_the_operator_fills_it_in(): void
    {
        config(['myfiesta.contact' => [
            'company_name' => null,
            'company_number' => '',
            'support_email' => null,
            'privacy_email' => null,
            'phone' => '',
            'addresses' => ['CA' => null, 'NG' => ''],
        ]]);

        $this->getJson('/api/contact')
            ->assertOk()
            ->assertJsonPath('data.complete', false)
            // Blank reads as absent rather than as an empty string the page
            // would print as nothing after "Phone:".
            ->assertJsonPath('data.phone', null)
            ->assertJsonPath('data.company_number', null)
            ->assertJsonPath('data.addresses', []);
    }

    public function test_serves_what_the_operator_configured(): void
    {
        config(['myfiesta.contact' => [
            'company_name' => 'Example Events Inc.',
            'company_number' => 'Corporation no. 000000-0',
            'support_email' => 'support@example.test',
            'privacy_email' => 'privacy@example.test',
            'phone' => null,
            'addresses' => [
                'CA' => ' 1 Example Street, Toronto, ON ',
                'NG' => null,
            ],
        ]]);

        $this->getJson('/api/contact')
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=300, public')
            ->assertExactJson(['data' => [
                'company_name' => 'Example Events Inc.',
                'company_number' => 'Corporation no. 000000-0',
                'support_email' => 'support@example.test',
                'privacy_email' => 'privacy@example.test',
                'phone' => null,
                // Only the markets with an address, trimmed.
                'addresses' => [['country' => 'CA', 'address' => '1 Example Street, Toronto, ON']],
                'complete' => true,
            ]]);
    }

    public function test_is_not_complete_without_somewhere_post_arrives(): void
    {
        config(['myfiesta.contact' => [
            'company_name' => 'Example Events Inc.',
            'support_email' => 'support@example.test',
            'addresses' => [],
        ]]);

        $this->getJson('/api/contact')->assertOk()->assertJsonPath('data.complete', false);
    }

    public function test_the_support_inbox_defaults_to_the_one_security_emails_reply_to(): void
    {
        $contact = $this->contactWith([
            'CONTACT_SUPPORT_EMAIL' => null,
            'CONTACT_PRIVACY_EMAIL' => null,
            'MAIL_SUPPORT_ADDRESS' => 'people@example.test',
        ]);

        // One inbox answered beats two addresses and one read.
        $this->assertSame('people@example.test', $contact['support_email']);
        $this->assertSame('people@example.test', $contact['privacy_email']);
    }

    public function test_an_empty_line_in_the_environment_still_falls_back(): void
    {
        // `CONTACT_SUPPORT_EMAIL=` reads as "", which env()'s own default does
        // not replace — and .env.production.example ships the line empty.
        $contact = $this->contactWith([
            'CONTACT_SUPPORT_EMAIL' => '',
            'CONTACT_PRIVACY_EMAIL' => '',
            'MAIL_SUPPORT_ADDRESS' => 'people@example.test',
        ]);

        $this->assertSame('people@example.test', $contact['support_email']);
        $this->assertSame('people@example.test', $contact['privacy_email']);
    }

    public function test_privacy_requests_can_have_an_inbox_of_their_own(): void
    {
        $contact = $this->contactWith([
            'CONTACT_SUPPORT_EMAIL' => 'support@example.test',
            'CONTACT_PRIVACY_EMAIL' => 'privacy@example.test',
            'MAIL_SUPPORT_ADDRESS' => null,
        ]);

        $this->assertSame('support@example.test', $contact['support_email']);
        $this->assertSame('privacy@example.test', $contact['privacy_email']);
    }

    /**
     * config/myfiesta.php as it would be built under these variables. Null
     * unsets one.
     *
     * @param  array<string, string|null>  $env
     * @return array<string, mixed>
     */
    private function contactWith(array $env): array
    {
        $saved = [];

        foreach ($env as $key => $value) {
            $saved[$key] = [$_SERVER[$key] ?? null, $_ENV[$key] ?? null, getenv($key)];

            if ($value === null) {
                unset($_SERVER[$key], $_ENV[$key]);
                putenv($key);
            } else {
                $_SERVER[$key] = $_ENV[$key] = $value;
                putenv("{$key}={$value}");
            }
        }

        try {
            return (require config_path('myfiesta.php'))['contact'];
        } finally {
            foreach ($saved as $key => [$server, $environment, $process]) {
                if ($server === null) {
                    unset($_SERVER[$key]);
                } else {
                    $_SERVER[$key] = $server;
                }

                if ($environment === null) {
                    unset($_ENV[$key]);
                } else {
                    $_ENV[$key] = $environment;
                }

                $process === false ? putenv($key) : putenv("{$key}={$process}");
            }
        }
    }
}
