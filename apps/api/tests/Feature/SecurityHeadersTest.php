<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The headers every response from the API and the admin panel carries.
 *
 * Nothing this application serves is meant to be framed, sniffed into a
 * different type, or told where it was linked from — and the admin panel,
 * which can refund and settle, is the page most worth framing under somebody
 * else's buttons. Set by the application rather than nginx, so these tests
 * hold them and `artisan serve` behaves as production does.
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    private function assertHardened(TestResponse $response): void
    {
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'same-origin');
        $this->assertStringContainsString('camera=()', (string) $response->headers->get('Permissions-Policy'));
        $this->assertStringContainsString("frame-ancestors 'none'", (string) $response->headers->get('Content-Security-Policy'));
    }

    public function test_the_api_answers_with_a_policy_that_runs_nothing(): void
    {
        $response = $this->getJson('/api/events');

        $this->assertHardened($response);
        $this->assertStringContainsString("default-src 'none'", $response->headers->get('Content-Security-Policy'));
    }

    public function test_errors_carry_them_too(): void
    {
        $this->assertHardened($this->getJson('/api/no-such-thing')->assertNotFound());
    }

    public function test_the_admin_panel_cannot_be_framed(): void
    {
        $response = $this->get('/admin');

        $this->assertHardened($response);
        // Livewire and Alpine evaluate their own expressions, so the panel's
        // policy is about framing, not scripts.
        $this->assertStringNotContainsString('script-src', $response->headers->get('Content-Security-Policy'));
    }

    public function test_the_pages_behind_emailed_links_load_nothing_from_anywhere_else(): void
    {
        $response = $this->get('/sign-up/'.Str::uuid().'?expires=1&signature=nope')->assertStatus(410);

        $this->assertHardened($response);
        $policy = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("default-src 'none'", $policy);
        $this->assertStringContainsString("form-action 'self'", $policy);
    }

    public function test_a_file_opened_in_a_tab_runs_nothing(): void
    {
        // An "image" uploaded as an identity document that is really an SVG
        // with a script in it, opened by a member of staff on this origin.
        Route::get('/_probe/document', fn () => response(
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.cookie)</script></svg>',
            200,
            ['Content-Type' => 'image/svg+xml'],
        ));

        $response = $this->get('/_probe/document')->assertOk();

        $this->assertHardened($response);
        $policy = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("script-src 'none'", $policy);
        // No default-src: a browser shows a PDF through a viewer that one
        // would refuse.
        $this->assertStringNotContainsString('default-src', $policy);
    }

    public function test_https_is_insisted_on_only_over_https(): void
    {
        $this->getJson('/api/events')->assertHeaderMissing('Strict-Transport-Security');

        $this->getJson('https://localhost/api/events')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }
}
