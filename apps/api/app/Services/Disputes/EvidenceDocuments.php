<?php

namespace App\Services\Disputes;

use App\Contracts\Payments\EvidenceFile;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The documents that go with an answer, as PDFs, from Blade.
 *
 * Four of them, each written from the case file and nothing else: the
 * receipt; the service documentation — the tickets issued, delivered, opened
 * and scanned in, and the night itself, in the order it happened; the refund
 * policy as the buyer's version said it, with how it was accepted; and every
 * email we sent the buyer. For Paystack, which takes one file with an answer,
 * the four as one.
 *
 * Rendered when asked for and never written to a public disk: a preview is
 * made on the spot, and what is sent is kept on the private disk by the desk
 * (DisputeDesk). No ticket code is in any of them — tickets are named by type
 * and the masked end of the code (DisputeAnswerTest reads every one).
 *
 * Dompdf is told to fetch nothing and run nothing: the HTML is ours, but a
 * buyer's name or an event's title is somebody's typing, and a renderer that
 * follows links is a way to make this server ask for things.
 */
final class EvidenceDocuments
{
    private const VIEWS = [
        'receipt' => 'disputes.pdf.receipt',
        'service_documentation' => 'disputes.pdf.service-documentation',
        'refund_policy' => 'disputes.pdf.refund-policy',
        'customer_communication' => 'disputes.pdf.customer-communication',
        'evidence_pack' => 'disputes.pdf.pack',
    ];

    public function render(CaseFile $case, string $kind): EvidenceFile
    {
        return new EvidenceFile(
            kind: $kind,
            name: Str::slug(str_replace('_', '-', $kind)).'-'.$case->order->reference.'.pdf',
            bytes: $this->pdf($this->html($case, $kind)),
        );
    }

    /** The document as HTML, before it becomes a PDF. */
    public function html(CaseFile $case, string $kind): string
    {
        $view = self::VIEWS[$kind] ?? throw new InvalidArgumentException("There is no evidence document called {$kind}.");

        return view($view, $this->data($case, $kind))->render();
    }

    /** @return array<string, mixed> */
    private function data(CaseFile $case, string $kind): array
    {
        $site = rtrim((string) config('app.public_url'), '/');

        return [
            'case' => $case,
            'title' => EvidenceDraft::DOCUMENTS[$kind] ?? $kind,
            'receipt' => $case->receipt(),
            'timeline' => $case->timeline(),
            // The policy's own links point at the site ("/terms"), which on
            // paper needs the site's address in front of it.
            'policyHtml' => $case->policy === null ? null : str_replace('href="/', 'href="'.$site.'/', Str::markdown($case->policy, [
                'html_input' => 'strip',
                'allow_unsafe_links' => false,
            ])),
            'checkbox' => EvidenceDraft::CHECKBOX,
            'site' => $site,
            'prepared' => CaseFile::at(now()),
        ];
    }

    private function pdf(string $html): string
    {
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $options->setDefaultPaperSize('a4');
        // Nothing on this server's disk is reachable from a document either.
        $options->setChroot([resource_path('views/disputes')]);
        // Font measurements are worked out once and kept here rather than
        // beside the fonts in vendor/, which a container may not let us write.
        $cache = storage_path('framework/cache/dompdf');
        File::ensureDirectoryExists($cache);
        $options->setFontCache($cache);

        $pdf = new Dompdf($options);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->render();

        return (string) $pdf->output();
    }
}
