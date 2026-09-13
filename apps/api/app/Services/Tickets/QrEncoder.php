<?php

namespace App\Services\Tickets;

use BaconQrCode\Encoder\Encoder;

/**
 * The thing a door scanner actually reads.
 *
 * Until this existed the platform had a scanner endpoint, partial admission and
 * a ledger, and no way to put a ticket in front of a camera — the code was
 * printed as text in an email and somebody was expected to read it out. A
 * ticketing platform whose tickets cannot be scanned is not one.
 *
 * SVG rather than a raster. It is sharp on every screen a ticket gets opened
 * on, it survives the pinch-zoom people do at a door in bad light, it costs no
 * image extension in PHP, and it compresses to less than a PNG of the same
 * legibility.
 *
 * What goes inside is the ticket code and nothing else. Encoding a URL would
 * mean a scanner at a door with no signal cannot resolve it, and encoding
 * anything personal would put a guest's name on a screen that gets photographed
 * by whoever is standing behind them in the queue.
 */
class QrEncoder
{
    /**
     * Error correction level M — about 15% of the symbol can be obscured and
     * it still reads. A ticket is a thing on a cracked phone screen behind a
     * fingerprint, and the levels below this assume clean printed media.
     */
    private const ECC = 'M';

    /** Drawn at one unit per module; the browser scales it. */
    private const QUIET_ZONE = 4;

    public function svg(string $code, int $size = 240, ?string $label = null): string
    {
        $matrix = Encoder::encode($code, \BaconQrCode\Common\ErrorCorrectionLevel::valueOf(self::ECC))
            ->getMatrix();

        $width = $matrix->getWidth();
        $span = $width + self::QUIET_ZONE * 2;

        $paths = [];

        for ($y = 0; $y < $width; $y++) {
            for ($x = 0; $x < $width; $x++) {
                if ($matrix->get($x, $y) === 1) {
                    // One rect per dark module. Merging runs would shrink the
                    // output further, and the saving is not worth the chance of
                    // an off-by-one that makes a ticket unreadable.
                    $paths[] = sprintf(
                        '<rect x="%d" y="%d" width="1" height="1"/>',
                        $x + self::QUIET_ZONE,
                        $y + self::QUIET_ZONE,
                    );
                }
            }
        }

        return sprintf(
            // shape-rendering keeps the modules hard-edged when the browser
            // scales the viewBox up; without it a scaled QR blurs at the module
            // boundaries and readers start failing.
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %1$d %1$d" width="%2$d" '
            .'height="%2$d" shape-rendering="crispEdges" role="img" aria-label="%3$s">'
            // The white ground is part of the symbol, not decoration: a QR
            // drawn transparently over a dark page inverts and will not scan.
            .'<rect width="%1$d" height="%1$d" fill="#ffffff"/>'
            .'<g fill="#000000">%4$s</g></svg>',
            $span,
            $size,
            e($label ?? 'Ticket '.$code),
            implode('', $paths),
        );
    }

    /** The same symbol as a data URI, for an <img> or an email. */
    public function dataUri(string $code, int $size = 240, ?string $label = null): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode($this->svg($code, $size, $label));
    }
}
