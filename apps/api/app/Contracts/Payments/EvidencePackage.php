<?php

namespace App\Contracts\Payments;

use Closure;

/**
 * Everything one answer to a dispute sends, handed to the processor's adapter.
 *
 * The words are ready; the documents are rendered only when the adapter asks
 * for one, and only if it was not given to the processor on an earlier try.
 * Each document the adapter hands over is remembered straight away
 * (remember), outside any transaction, so a failure after it — the processor
 * refusing a field, a timeout — leaves the upload on record and a second try
 * does not upload it again.
 */
final class EvidencePackage
{
    /** @var array<string, true> every handle this try gave the processor, old or new */
    private array $handedOver = [];

    /**
     * @param  array<string, string>  $fields  the words, named as the processor names its fields
     * @param  array<string, mixed>  $enhanced  Stripe's enhanced_evidence, or nothing
     * @param  list<string>  $files  the documents that go with it, by kind
     * @param  Closure(string): EvidenceFile  $render  a document, rendered
     * @param  array<string, string>  $uploaded  what an earlier try already handed over, kind => the processor's handle for it
     * @param  Closure(string, string, EvidenceFile|null, string|null): void  $remember  keep a handle the moment it is given
     * @param  array<string, string>  $digests  kind => a digest of what an earlier try's handle stands for, where one was kept
     * @param  (Closure(): void)|null  $spent  the processor has answered under this try's keys, so the next try needs new ones
     */
    public function __construct(
        public readonly array $fields,
        public readonly array $enhanced,
        public readonly array $files,
        private readonly Closure $render,
        private array $uploaded,
        private readonly Closure $remember,
        /** Starts every idempotency key sent for this dispute. */
        public readonly string $keyPrefix,
        /** A line or two for a processor that wants a note with the answer. */
        public readonly string $message = '',
        private array $digests = [],
        private readonly ?Closure $spent = null,
    ) {}

    public function file(string $kind): EvidenceFile
    {
        return ($this->render)($kind);
    }

    /**
     * What an earlier try handed over for this kind, if anything.
     *
     * Given $of — a digest of what is about to go — only a handle for that
     * same thing: words corrected since the processor was given them are not
     * the words it has, and go again.
     */
    public function uploaded(string $kind, ?string $of = null): ?string
    {
        $handle = $this->uploaded[$kind] ?? null;

        if ($handle === null || ($of !== null && ($this->digests[$kind] ?? null) !== $of)) {
            return null;
        }

        $this->handedOver[$kind] = true;

        return $handle;
    }

    public function remember(string $kind, string $handle, ?EvidenceFile $file = null, ?string $of = null): void
    {
        $this->uploaded[$kind] = $handle;
        $this->handedOver[$kind] = true;

        if ($of !== null) {
            $this->digests[$kind] = $of;
        }

        ($this->remember)($kind, $handle, $file, $of);
    }

    /** @return list<string> the kinds this try gave the processor, whether uploaded now or before */
    public function handedOver(): array
    {
        return array_keys($this->handedOver);
    }

    /**
     * An idempotency key for one step, changing with what the step sends.
     *
     * The same request repeated is the same key, so the processor answers a
     * retry with the first answer. Different words — a field corrected after
     * the processor refused it — are a different key, since a processor
     * refuses a key it has seen with other parameters.
     *
     * @param  array<string, mixed>  $payload
     */
    public function key(string $step, array $payload = []): string
    {
        return $this->keyPrefix.'-'.$step.($payload === [] ? '' : '-'.substr(hash('sha256', (string) json_encode($payload)), 0, 24));
    }

    /**
     * The processor answered one of this try's requests with a refusal.
     *
     * Stripe keeps its answer to a key — a failure included — and gives it
     * back to the same key for a day at least, so the same press again would
     * only be told the same thing. Said here, the next try is sent under new
     * keys. Not said when no answer came back at all: the next try then asks
     * under the same key, and learns what became of the first.
     */
    public function spent(): void
    {
        if ($this->spent !== null) {
            ($this->spent)();
        }
    }
}
