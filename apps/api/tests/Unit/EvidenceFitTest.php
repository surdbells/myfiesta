<?php

namespace Tests\Unit;

use App\Services\Disputes\CompellingEvidence;
use App\Services\Disputes\EvidenceDraft;
use PHPUnit\Framework\TestCase;

/**
 * A field cut to fit is still a field the processor takes.
 *
 * Stripe refuses a field over its limit, and the page will not save one
 * either — so a draft that cut a long access log to the limit and then added
 * its note on the end would be a draft staff could neither save nor send,
 * over words they never wrote.
 */
class EvidenceFitTest extends TestCase
{
    public function test_no_field_is_ever_longer_than_it_may_be_once_cut(): void
    {
        $draft = new EvidenceDraft(new CompellingEvidence);

        foreach (EvidenceDraft::FIELDS as $name => $spec) {
            foreach ([
                str_repeat('a', $spec['max'] + 5000),
                str_repeat("a line of the access log\n", intdiv($spec['max'], 10)),
                str_repeat('é', $spec['max'] + 1),
                str_repeat('a', $spec['max'] + 1),
            ] as $value) {
                $fitted = $draft->fit([$name => $value])[$name];

                $this->assertLessThanOrEqual($spec['max'], mb_strlen($fitted), "{$name} came out longer than {$spec['max']}.");

                if ($spec['max'] > mb_strlen(EvidenceDraft::SHORTENED)) {
                    $this->assertStringEndsWith(EvidenceDraft::SHORTENED, $fitted, "{$name} was cut without saying so.");
                }
            }
        }
    }

    public function test_a_long_log_is_cut_at_a_line(): void
    {
        $log = implode("\n", array_fill(0, 2000, '27 Sep 2026, 17:30:46 UTC | ticket page opened | IP 198.51.100.23 | Mozilla/5.0'));

        $fitted = (new EvidenceDraft(new CompellingEvidence))->fit(['access_activity_log' => $log])['access_activity_log'];
        $kept = substr($fitted, 0, -strlen(EvidenceDraft::SHORTENED));

        $this->assertLessThanOrEqual(20000, mb_strlen($fitted));
        $this->assertStringEndsWith('Mozilla/5.0', $kept);
    }

    public function test_a_field_that_fits_is_left_as_it_is(): void
    {
        $this->assertSame(
            ['customer_name' => 'Ada Okafor'],
            (new EvidenceDraft(new CompellingEvidence))->fit(['customer_name' => 'Ada Okafor']),
        );
    }
}
