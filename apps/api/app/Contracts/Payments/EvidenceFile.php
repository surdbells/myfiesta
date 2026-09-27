<?php

namespace App\Contracts\Payments;

/**
 * One document of a dispute's evidence, rendered and ready to hand over.
 */
final readonly class EvidenceFile
{
    public function __construct(
        /** What it is: receipt, service_documentation, refund_policy, customer_communication, evidence_pack. */
        public string $kind,
        /** The name it is given at the processor, e.g. receipt-K7QX3M9A.pdf. */
        public string $name,
        public string $bytes,
        public string $mimeType = 'application/pdf',
    ) {}

    public function sha256(): string
    {
        return hash('sha256', $this->bytes);
    }

    public function size(): int
    {
        return strlen($this->bytes);
    }
}
