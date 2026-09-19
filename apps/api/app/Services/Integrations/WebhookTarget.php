<?php

namespace App\Services\Integrations;

/**
 * Whether a URL an organizer typed is somewhere we may send a request.
 *
 * A webhook is a URL a customer chose and our server fetches — which is the
 * textbook shape of server-side request forgery. Point one at 169.254.169.254
 * and it reads a cloud provider's instance credentials; at 127.0.0.1:5432 and
 * it knocks on our own database; at 10.0.0.5 and it walks a private network
 * nobody outside was meant to reach.
 *
 * So the check is on the address the name resolves to, not on the name: a
 * perfectly public-looking hostname can resolve to a private address. And the
 * connection is then pinned to the address that was checked, so a name that
 * answers differently a second time — DNS rebinding — cannot slip a private
 * address in between the check and the request.
 */
class WebhookTarget
{
    /**
     * Refuse, with a sentence an organizer can act on, or return the host,
     * port and address to pin the connection to.
     *
     * @return array{host: string, port: int, ip: string}
     *
     * @throws UnsafeWebhookTarget
     */
    public function check(string $url): array
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new UnsafeWebhookTarget('That is not a web address.');
        }

        // Everything a webhook carries — names, addresses, what they paid —
        // crosses the internet. Unencrypted, it crosses it in the clear.
        if (strtolower($parts['scheme']) !== 'https') {
            throw new UnsafeWebhookTarget('Webhooks are only sent over https.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new UnsafeWebhookTarget('Put credentials in the receiver, not in the address — we sign every delivery instead.');
        }

        $host = strtolower(trim($parts['host'], '[]'));
        $port = (int) ($parts['port'] ?? 443);

        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.internal')) {
            throw new UnsafeWebhookTarget('That address is not reachable from the internet.');
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->resolve($host);

        if ($addresses === []) {
            throw new UnsafeWebhookTarget('That address does not resolve to anywhere.');
        }

        // Every address, not the first: a name that answers with one public
        // and one private address is a name that can be made to use either.
        foreach ($addresses as $ip) {
            if (! $this->isPublic($ip)) {
                throw new UnsafeWebhookTarget('That address is not reachable from the internet.');
            }
        }

        return ['host' => $host, 'port' => $port, 'ip' => $addresses[0]];
    }

    /**
     * What a name resolves to, both families.
     *
     * Its own method so a test can say what the DNS would have answered
     * without the test depending on the DNS.
     *
     * @return list<string>
     */
    public function resolve(string $host): array
    {
        $v4 = @gethostbynamel($host) ?: [];

        $v6 = array_map(
            fn (array $record) => $record['ipv6'],
            @dns_get_record($host, DNS_AAAA) ?: [],
        );

        return array_values(array_unique([...$v4, ...$v6]));
    }

    private function isPublic(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }
}
