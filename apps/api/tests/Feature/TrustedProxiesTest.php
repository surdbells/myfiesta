<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Where a request came from is believed only from the proxy in front.
 *
 * The API's nginx used to take the client's address from X-Forwarded-For
 * whoever sent it, and to call a request https whenever X-Forwarded-Proto
 * said so. Every per-address limit here — guessing passwords included — was a
 * header away from not applying, and plain http could pass for https. Now
 * both are believed only from the addresses in TRUSTED_PROXIES.
 */
class TrustedProxiesTest extends TestCase
{
    use RefreshDatabase;

    private const BALANCER = '10.0.0.5';

    private const STRANGER = '203.0.113.7';

    protected function setUp(): void
    {
        parent::setUp();

        config(['trustedproxy.proxies' => ['10.0.0.0/8']]);
        RateLimiter::clear('login:ada@example.com|'.self::STRANGER);

        // What the application believes about a request, and nothing else.
        Route::get('/_probe/client', fn (Request $request) => response()->json([
            'ip' => $request->ip(),
            'secure' => $request->isSecure(),
            'host' => $request->getHost(),
        ]));
    }

    /** @param  array<string, string>  $headers */
    private function probe(string $from, array $headers = [])
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $from])
            ->withHeaders($headers)
            ->getJson('/_probe/client');
    }

    public function test_a_client_cannot_name_its_own_address(): void
    {
        $this->probe(self::STRANGER, ['X-Forwarded-For' => '198.51.100.1'])
            ->assertJsonPath('ip', self::STRANGER);
    }

    public function test_the_balancer_says_who_the_client_is(): void
    {
        $this->probe(self::BALANCER, ['X-Forwarded-For' => '198.51.100.1'])
            ->assertJsonPath('ip', '198.51.100.1');
    }

    public function test_an_address_the_client_added_before_the_balancer_is_not_believed(): void
    {
        // The client wrote the first entry; the balancer appended the address
        // it actually saw. The last untrusted hop is the client.
        $this->probe(self::BALANCER, ['X-Forwarded-For' => '192.0.2.99, 198.51.100.1'])
            ->assertJsonPath('ip', '198.51.100.1');
    }

    public function test_plain_http_cannot_claim_to_be_https(): void
    {
        $response = $this->probe(self::STRANGER, ['X-Forwarded-Proto' => 'https'])
            ->assertJsonPath('secure', false);

        $response->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_the_balancer_says_when_it_was_https(): void
    {
        $this->probe(self::BALANCER, ['X-Forwarded-Proto' => 'https'])
            ->assertJsonPath('secure', true)
            ->assertHeader('Strict-Transport-Security');
    }

    public function test_a_forwarded_host_is_never_believed(): void
    {
        // Not even from the balancer: the Host header arrives intact, and a
        // second copy is only a way to put another site's name into links.
        $this->probe(self::BALANCER, ['X-Forwarded-Host' => 'evil.example'])
            ->assertJsonPath('host', 'localhost');
    }

    public function test_nobody_is_trusted_when_nobody_is_named(): void
    {
        config(['trustedproxy.proxies' => []]);

        $this->probe(self::BALANCER, ['X-Forwarded-For' => '198.51.100.1', 'X-Forwarded-Proto' => 'https'])
            ->assertJsonPath('ip', self::BALANCER)
            ->assertJsonPath('secure', false);
    }

    public function test_the_password_guessing_limit_cannot_be_dodged_by_rotating_the_header(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        // Five wrong guesses per address and account, then a wait. Each guess
        // claims to come from somewhere new; none of them is believed.
        for ($i = 1; $i <= 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => self::STRANGER])
                ->withHeaders(['X-Forwarded-For' => "198.51.100.{$i}"])
                ->postJson('/api/auth/login', ['email' => 'ada@example.com', 'password' => "guess number {$i}"])
                ->assertStatus(422);
        }

        $this->withServerVariables(['REMOTE_ADDR' => self::STRANGER])
            ->withHeaders(['X-Forwarded-For' => '198.51.100.200'])
            ->postJson('/api/auth/login', ['email' => 'ada@example.com', 'password' => 'guess number 6'])
            ->assertStatus(429);
    }

    public function test_the_list_is_read_from_the_environment_as_written(): void
    {
        $read = function (string $value) {
            $_SERVER['TRUSTED_PROXIES'] = $_ENV['TRUSTED_PROXIES'] = $value;
            putenv("TRUSTED_PROXIES={$value}");

            try {
                return (require config_path('trustedproxy.php'))['proxies'];
            } finally {
                unset($_SERVER['TRUSTED_PROXIES'], $_ENV['TRUSTED_PROXIES']);
                putenv('TRUSTED_PROXIES');
            }
        };

        $this->assertSame(['10.0.0.0/8', '172.16.0.0/12'], $read(' 10.0.0.0/8 , 172.16.0.0/12,'));

        // Unset is nobody, not Laravel's "decide for me".
        $this->assertSame([], $read(''));
    }
}
