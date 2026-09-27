<?php

namespace Tests\Feature;

use App\Support\Preflight;
use App\Support\PreflightFailed;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Production refuses to start with a secret missing or a sender pretending.
 *
 * The .env.production.example this platform shipped left both webhook secrets
 * empty and set texts to the log driver, and nothing looked at either at
 * start-up. A blank webhook secret is a signature anybody can make; a log
 * driver reports every text as sent. Both look like a working platform until a
 * buyer is at a door with no ticket.
 *
 * The first half checks the list itself. The second starts real processes
 * with APP_ENV=production, because the point is what happens at start-up —
 * and that build-time commands, which run with no secrets at all, still work.
 */
class PreflightTest extends TestCase
{
    /** A configuration production would accept. */
    private function ready(): void
    {
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'app.debug' => false,
            'payments.stripe.secret_key' => 'sk_live_ready',
            'payments.stripe.webhook_secret' => 'whsec_ready',
            'payments.paystack.secret_key' => 'sk_live_paystack',
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.example.test',
            'sms.driver' => 'termii',
            'sms.countries' => ['234'],
            'sms.termii.key' => 'termii-key',
            'sms.inbound_secret' => 'inbound-secret',
            'trustedproxy.proxies' => ['10.0.0.0/8'],
            'operations.backup.encryption_key' => self::backupKey(),
        ]);
    }

    /**
     * 32 bytes of base64, the shape `php artisan backup:key` prints.
     *
     * Built as the test runs, never written out: a key-shaped string in source
     * is exactly what the secret scan on every push stops, test or not.
     */
    private static function backupKey(): string
    {
        return base64_encode(str_repeat('b', SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES));
    }

    public function test_a_complete_configuration_has_nothing_to_report(): void
    {
        $this->ready();

        $this->assertSame([], Preflight::problems());
    }

    public function test_a_blank_webhook_secret_or_gateway_key_is_named(): void
    {
        $this->ready();
        config([
            'payments.stripe.webhook_secret' => '',
            'payments.stripe.secret_key' => null,
            'payments.paystack.secret_key' => '',
        ]);

        $this->assertEqualsCanonicalizing(
            ['STRIPE_WEBHOOK_SECRET', 'STRIPE_SECRET_KEY', 'PAYSTACK_SECRET_KEY'],
            array_keys(Preflight::problems()),
        );
    }

    public function test_senders_that_deliver_nowhere_are_named(): void
    {
        $this->ready();
        config(['mail.default' => 'log', 'sms.driver' => 'log']);

        $this->assertEqualsCanonicalizing(['MAIL_MAILER', 'SMS_DRIVER'], array_keys(Preflight::problems()));

        config(['mail.default' => 'array']);
        $this->assertArrayHasKey('MAIL_MAILER', Preflight::problems());
    }

    public function test_a_real_text_provider_needs_its_credentials_and_a_way_to_stop(): void
    {
        $this->ready();
        config(['sms.termii.key' => '', 'sms.inbound_secret' => null]);

        $this->assertEqualsCanonicalizing(['TERMII_API_KEY', 'SMS_INBOUND_SECRET'], array_keys(Preflight::problems()));

        config(['sms.driver' => 'twilio', 'sms.inbound_secret' => 'inbound-secret']);
        $this->assertEqualsCanonicalizing(
            ['TWILIO_ACCOUNT_SID', 'TWILIO_AUTH_TOKEN', 'TWILIO_FROM'],
            array_keys(Preflight::problems()),
        );
    }

    public function test_sending_no_texts_at_all_is_honest_and_allowed(): void
    {
        $this->ready();

        // No country is worth texting, so nothing reaches the driver and
        // nothing is claimed sent, whichever driver is named.
        config(['sms.driver' => 'log', 'sms.countries' => []]);

        $this->assertSame([], Preflight::problems());
    }

    public function test_debug_a_missing_key_and_untrusted_proxies_are_named(): void
    {
        $this->ready();
        config(['app.debug' => true, 'app.key' => '', 'trustedproxy.proxies' => []]);

        $this->assertEqualsCanonicalizing(['APP_DEBUG', 'APP_KEY', 'TRUSTED_PROXIES'], array_keys(Preflight::problems()));

        // Trusting everybody is the bypass, not the fix.
        config(['app.debug' => false, 'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)), 'trustedproxy.proxies' => ['0.0.0.0/0']]);
        $this->assertArrayHasKey('TRUSTED_PROXIES', Preflight::problems());
    }

    public function test_backups_without_a_key_or_with_a_broken_one_are_named(): void
    {
        $this->ready();

        // Every production takes a nightly backup, to the volume unless a
        // bucket is named, and with no key each one is every buyer's details
        // in the clear.
        foreach (['volume', 's3'] as $target) {
            config(['operations.backup.target' => $target, 'operations.backup.encryption_key' => null]);

            $this->assertSame(['BACKUP_ENCRYPTION_KEY'], array_keys(Preflight::problems()), $target);
            $this->assertStringContainsString('is empty', Preflight::problems()['BACKUP_ENCRYPTION_KEY']);
        }

        // There and wrong is not "no key": backup:run refuses it, so every
        // night would fail.
        config(['operations.backup.encryption_key' => 'not-a-key']);
        $this->assertStringContainsString('not 32 bytes', Preflight::problems()['BACKUP_ENCRYPTION_KEY']);
        $this->assertStringNotContainsString('not-a-key', Preflight::problems()['BACKUP_ENCRYPTION_KEY']);

        config(['operations.backup.encryption_key' => 'base64:'.self::backupKey()]);
        $this->assertSame([], Preflight::problems());
    }

    public function test_enforcing_lists_every_problem_at_once_and_no_secret(): void
    {
        $this->ready();
        config(['payments.stripe.webhook_secret' => '', 'sms.driver' => 'log']);

        try {
            Preflight::enforce();
            $this->fail('A blank webhook secret was accepted.');
        } catch (PreflightFailed $failed) {
            $this->assertStringContainsString('STRIPE_WEBHOOK_SECRET', $failed->getMessage());
            $this->assertStringContainsString('SMS_DRIVER', $failed->getMessage());
            // It lands in a container log. Names, never values.
            $this->assertStringNotContainsString('sk_live_ready', $failed->getMessage());
            $this->assertStringNotContainsString('termii-key', $failed->getMessage());
        }
    }

    public function test_the_command_reports_outside_production_and_fails_when_strict(): void
    {
        $this->ready();
        config(['payments.stripe.webhook_secret' => '']);

        $this->artisan('app:preflight')
            ->expectsOutputToContain('STRIPE_WEBHOOK_SECRET')
            ->assertExitCode(0);

        $this->artisan('app:preflight', ['--strict' => true])
            ->expectsOutputToContain('STRIPE_WEBHOOK_SECRET')
            ->assertExitCode(1);

        config(['payments.stripe.webhook_secret' => 'whsec_ready']);

        $this->artisan('app:preflight', ['--strict' => true])
            ->expectsOutputToContain('Ready')
            ->assertExitCode(0);
    }

    // --- real processes, in production ----------------------------------------

    /**
     * Run a PHP process in production, with a configuration that is complete
     * apart from what $overrides takes away.
     *
     * @param  list<string>  $command
     * @param  array<string, string>  $overrides
     */
    private function production(array $command, array $overrides = []): Process
    {
        // Relative, so Laravel resolves it under this app on any platform (it
        // takes only a leading slash as absolute), and inside a directory git
        // already ignores.
        $scratch = 'storage/framework/cache/preflight-'.bin2hex(random_bytes(4));

        $process = new Process([PHP_BINARY, ...$command], base_path(), [
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_KEY' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'STRIPE_SECRET_KEY' => 'sk_live_ready',
            'STRIPE_WEBHOOK_SECRET' => 'whsec_ready',
            'PAYSTACK_SECRET_KEY' => 'sk_live_paystack',
            'MAIL_MAILER' => 'smtp',
            'MAIL_HOST' => 'smtp.example.test',
            'SMS_DRIVER' => 'termii',
            'SMS_COUNTRIES' => '234',
            'TERMII_API_KEY' => 'termii-key',
            'SMS_INBOUND_SECRET' => 'inbound-secret',
            'TRUSTED_PROXIES' => '10.0.0.0/8',
            'BACKUP_ENCRYPTION_KEY' => self::backupKey(),
            'LOG_CHANNEL' => 'stderr',
            'DB_DATABASE' => (string) config('database.connections.pgsql.database'),
            // Never this checkout's own caches: a config cache left behind
            // would pin every later process to these values.
            'APP_CONFIG_CACHE' => $scratch.'-config.php',
            'APP_PACKAGES_CACHE' => $scratch.'-packages.php',
            'APP_SERVICES_CACHE' => $scratch.'-services.php',
            ...$overrides,
        ]);

        $process->setTimeout(60)->run();

        foreach (['config', 'packages', 'services'] as $cache) {
            @unlink(base_path("{$scratch}-{$cache}.php"));
        }

        return $process;
    }

    public function test_production_serves_with_a_complete_configuration(): void
    {
        $up = $this->production(['artisan', 'app:preflight']);
        $this->assertSame(0, $up->getExitCode(), $up->getOutput().$up->getErrorOutput());

        $web = $this->production(['public/index.php'], $this->request('/up'));
        $this->assertStringContainsString('"status":"up"', $web->getOutput(), $web->getErrorOutput());
    }

    public function test_the_start_up_check_fails_the_container_and_names_the_variable(): void
    {
        $check = $this->production(['artisan', 'app:preflight'], ['STRIPE_WEBHOOK_SECRET' => '', 'SMS_DRIVER' => 'log']);

        $this->assertSame(1, $check->getExitCode());
        $this->assertStringContainsString('STRIPE_WEBHOOK_SECRET', $check->getOutput());
        $this->assertStringContainsString('SMS_DRIVER', $check->getOutput());
        $this->assertStringNotContainsString('sk_live_ready', $check->getOutput());
    }

    public function test_production_will_not_start_with_its_backups_unencrypted(): void
    {
        $check = $this->production(['artisan', 'app:preflight'], ['BACKUP_ENCRYPTION_KEY' => '']);

        $this->assertSame(1, $check->getExitCode(), $check->getOutput().$check->getErrorOutput());
        $this->assertStringContainsString('BACKUP_ENCRYPTION_KEY', $check->getOutput());

        // Whatever the target: the bucket is somebody else's disk, which is
        // more reason for a key, not less.
        $bucket = $this->production(['artisan', 'app:preflight'], ['BACKUP_ENCRYPTION_KEY' => '', 'BACKUP_TARGET' => 's3']);
        $this->assertSame(1, $bucket->getExitCode());
        $this->assertStringContainsString('BACKUP_ENCRYPTION_KEY', $bucket->getOutput());
    }

    public function test_the_worker_refuses_to_start(): void
    {
        $worker = $this->production(
            ['artisan', 'queue:work', '--once', '--stop-when-empty'],
            ['PAYSTACK_SECRET_KEY' => '', 'MAIL_MAILER' => 'log'],
        );

        $this->assertNotSame(0, $worker->getExitCode());
        $this->assertStringContainsString('PAYSTACK_SECRET_KEY', $worker->getOutput().$worker->getErrorOutput());
        $this->assertStringContainsString('MAIL_MAILER', $worker->getOutput().$worker->getErrorOutput());
    }

    public function test_a_web_request_is_refused_before_any_route_runs(): void
    {
        $web = $this->production(['public/index.php'], [
            ...$this->request('/up'),
            'STRIPE_WEBHOOK_SECRET' => '',
        ]);

        $this->assertStringNotContainsString('"status":"up"', $web->getOutput());
        $this->assertStringContainsString('STRIPE_WEBHOOK_SECRET', $web->getErrorOutput());
    }

    public function test_build_time_commands_still_run_without_any_secret(): void
    {
        $bare = [
            'APP_KEY' => '',
            'STRIPE_SECRET_KEY' => '',
            'STRIPE_WEBHOOK_SECRET' => '',
            'PAYSTACK_SECRET_KEY' => '',
            'SMS_DRIVER' => 'log',
            'TRUSTED_PROXIES' => '',
            'BACKUP_ENCRYPTION_KEY' => '',
        ];

        // An image is built with none of these, and config:cache,
        // package:discover and the rest have to work there.
        foreach ([['package:discover'], ['config:cache'], ['route:list', '--path=up']] as $command) {
            $run = $this->production(['artisan', ...$command], $bare);

            $this->assertSame(0, $run->getExitCode(), implode(' ', $command).': '.$run->getOutput().$run->getErrorOutput());
        }
    }

    // --- the API image's entrypoint -----------------------------------------

    /**
     * ops/docker/api-entrypoint.sh, with php and docker-php-entrypoint stood
     * in for by scripts that say what they were asked to do. The stand-in php
     * fails whatever it is asked, as app:preflight does in a production that
     * is not ready — or, when $ready, does whatever it is asked.
     *
     * @param  list<string>  $command
     */
    private function entrypoint(array $command, bool $ready = false): Process
    {
        $sh = (new ExecutableFinder)->find('sh');

        if ($sh === null) {
            $this->markTestSkipped('No sh here to run the entrypoint with.');
        }

        $bin = storage_path('framework/cache/entrypoint-'.bin2hex(random_bytes(4)));
        mkdir($bin);
        file_put_contents("{$bin}/php", "#!/bin/sh\necho \"php \$*\"\nexit ".($ready ? 0 : 1)."\n");
        file_put_contents("{$bin}/docker-php-entrypoint", "#!/bin/sh\necho \"started \$*\"\n");
        chmod("{$bin}/php", 0755);
        chmod("{$bin}/docker-php-entrypoint", 0755);

        // With its line endings stripped, as the image does when it copies
        // the script in: a Windows checkout writes CRLF.
        $script = (string) file_get_contents(base_path('../../ops/docker/api-entrypoint.sh'));
        file_put_contents("{$bin}/entrypoint.sh", str_replace("\r\n", "\n", $script));

        // Windows spells it Path and matches either; setting both keeps the
        // stand-ins first whichever one the shell reads.
        $path = $bin.PATH_SEPARATOR.getenv('PATH');
        $env = PHP_OS_FAMILY === 'Windows' ? ['PATH' => $path, 'Path' => $path] : ['PATH' => $path];

        $process = new Process([$sh, "{$bin}/entrypoint.sh", ...$command], base_path(), $env);
        $process->setTimeout(30)->run();

        foreach (['php', 'docker-php-entrypoint', 'entrypoint.sh'] as $file) {
            @unlink("{$bin}/{$file}");
        }
        @rmdir($bin);

        return $process;
    }

    /** The three that serve, as compose.prod.yml starts them. */
    private const SERVING = [
        ['php-fpm'],
        ['-F'],
        ['php', 'artisan', 'queue:work', '--tries=3', '--max-time=3600', '--sleep=1'],
        ['php', 'artisan', 'schedule:work'],
    ];

    public function test_the_image_checks_before_anything_serves_and_lets_artisan_through(): void
    {
        // php-fpm, asked for by name or by an option for it, and the worker
        // and the scheduler do not start in a production that is not ready,
        // and nothing is cached for them…
        foreach (self::SERVING as $command) {
            $run = $this->entrypoint($command);

            $this->assertNotSame(0, $run->getExitCode(), $run->getOutput().$run->getErrorOutput());
            $this->assertStringContainsString('php artisan app:preflight', $run->getOutput());
            $this->assertStringNotContainsString('config:cache', $run->getOutput());
            $this->assertStringNotContainsString('started', $run->getOutput());
        }

        // …but artisan runs there unchecked, because that is how a box is put
        // right — including the key:generate the check itself tells the
        // operator to run. Nor does it build caches: a one-off command in a
        // container of its own has nobody to build them for, and cron's
        // schedule:run would build three of them a minute.
        foreach ([['key:generate', '--show'], ['tinker'], ['schedule:run']] as $command) {
            $repair = $this->entrypoint(['php', 'artisan', ...$command]);

            $this->assertSame(0, $repair->getExitCode(), $repair->getOutput().$repair->getErrorOutput());
            $this->assertStringNotContainsString('app:preflight', $repair->getOutput());
            $this->assertStringNotContainsString(':cache', $repair->getOutput());
            $this->assertStringContainsString('started php artisan '.implode(' ', $command), $repair->getOutput());
        }
    }

    /**
     * The caches api-migrate builds die with api-migrate: every container has
     * a filesystem of its own. So each one that serves builds its own, from
     * its own environment, after the check and before the process starts.
     */
    public function test_each_container_that_serves_caches_config_routes_and_events_as_it_starts(): void
    {
        foreach (self::SERVING as $command) {
            $run = $this->entrypoint($command, ready: true);
            $said = implode(' ', $command);

            $this->assertSame(0, $run->getExitCode(), $said.': '.$run->getOutput().$run->getErrorOutput());
            $this->assertSame([
                'php artisan app:preflight',
                'php artisan config:cache',
                'php artisan route:cache',
                'php artisan event:cache',
                "started {$said}",
            ], preg_split('/\r?\n/', trim($run->getOutput())), $said);
        }
    }

    public function test_the_migration_checks_before_it_touches_the_schema(): void
    {
        $compose = (string) file_get_contents(base_path('../../ops/docker/compose.prod.yml'));

        // The entrypoint leaves `sh -c` alone, so api-migrate asks itself,
        // and asks first: a bad deployment stops before the schema changes.
        $this->assertMatchesRegularExpression(
            '/api-migrate:.*?command:\s*>\s*sh -c "php artisan app:preflight\s+&& php artisan migrate --force/s',
            $compose,
        );

        // And builds each cache once, so a release that cannot cache stops
        // here, before any container that serves is replaced.
        $this->assertMatchesRegularExpression(
            '/api-migrate:.*?migrate --force\s+&& php artisan config:cache\s+&& php artisan route:cache\s+&& php artisan event:cache"/s',
            $compose,
        );
    }

    /**
     * What php-fpm would put in $_SERVER for a GET, for public/index.php run
     * from the command line.
     *
     * @return array<string, string>
     */
    private function request(string $path): array
    {
        return [
            'APP_RUNNING_IN_CONSOLE' => 'false',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => $path,
            'SCRIPT_NAME' => '/index.php',
            'SCRIPT_FILENAME' => base_path('public/index.php'),
            'SERVER_NAME' => 'api.example.test',
            'SERVER_PORT' => '80',
            'HTTP_HOST' => 'api.example.test',
            'HTTP_ACCEPT' => 'application/json',
        ];
    }
}
