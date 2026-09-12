<?php

namespace App\Support;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Event descriptions: formatted text an organizer writes and a stranger reads.
 *
 * The format is HTML because the data already is. The legacy platform's
 * descriptions came out of a WYSIWYG editor, and its events table carries
 * paragraphs, bold, lists and links — importing that as plain text printed
 * the tags themselves to every buyer. So the job here is not to invent a
 * format but to make the one that exists safe to render.
 *
 * Safety is an allowlist, not a blocklist. The small set of elements an event
 * description has any use for survives; every attribute but a link's href is
 * stripped; links are limited to http, https and mailto, and are forced to
 * open without handing the event page to whatever they point at. Anything
 * else is either unwrapped (a <div> or a <span style> keeps its words) or, for
 * the things that were never text — scripts, styles, frames — removed with
 * everything inside them.
 *
 * Plain text that arrives with no markup at all is turned into paragraphs
 * rather than run together, so a description typed into a textarea before
 * this existed still reads as the organizer laid it out.
 */
final class RichText
{
    /** Elements a description may use. Everything else is unwrapped or dropped. */
    private const ALLOWED = ['p', 'br', 'strong', 'em', 'u', 's', 'ul', 'ol', 'li', 'h2', 'h3', 'blockquote', 'a'];

    /** Never text. Removed along with whatever is inside them. */
    private const DROPPED = ['script', 'style', 'iframe', 'object', 'embed', 'noscript', 'template', 'svg', 'math', 'form', 'input', 'button', 'select', 'textarea', 'img', 'video', 'audio', 'head', 'title', 'meta', 'link'];

    /** Containers and old presentational tags: the tag goes, the words stay. */
    private const UNWRAPPED = ['div', 'span', 'font', 'section', 'article', 'header', 'footer', 'main', 'aside', 'center', 'small', 'big', 'sub', 'sup', 'mark', 'label', 'figure', 'figcaption', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'caption', 'dl', 'dt', 'dd', 'pre', 'code', 'hr', 'h1', 'h4', 'h5', 'h6', 'b', 'i', 'body', 'html'];

    private static ?HtmlSanitizer $sanitizer = null;

    /**
     * Safe HTML, or null for a description that says nothing.
     *
     * Null rather than an empty string, so "no description" has one
     * representation — a blank editor saves <p></p>, which is not a
     * description anybody wrote.
     */
    public static function clean(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }

        $input = trim($input);

        if ($input === '') {
            return null;
        }

        $html = self::looksLikeHtml($input) ? self::normalise($input) : self::paragraphs($input);

        $clean = trim(self::sanitizer()->sanitize($html));

        // Collapse what a WYSIWYG leaves behind: runs of empty paragraphs from
        // pressing Enter, and paragraphs holding nothing but a break.
        $clean = preg_replace('#<p>(\s|&nbsp;|&\#160;|<br\s*/?>)*</p>#u', '', $clean) ?? $clean;

        return self::toText($clean) === null ? null : $clean;
    }

    /**
     * The description as words, for places that cannot render markup: the
     * search snippet, a link preview in WhatsApp, structured data for Google.
     * Putting HTML in any of those shows the tags to the reader.
     */
    public static function toText(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        // Block boundaries become spaces before the tags go, or "Doors at 9pm</p><p>Dress code"
        // reads as "Doors at 9pmDress code".
        $spaced = preg_replace('#</?(p|br|li|h[1-6]|blockquote|div|ul|ol|tr)\b[^>]*>#i', ' ', $html) ?? $html;

        $text = html_entity_decode(strip_tags($spaced), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        return $text === '' ? null : $text;
    }

    private static function looksLikeHtml(string $input): bool
    {
        return (bool) preg_match('#</?[a-z][a-z0-9]*\b[^>]*>#i', $input);
    }

    /**
     * Old editors wrote <b> and <i>. Mapped to their semantic equivalents
     * before sanitizing, because the sanitizer can keep or drop an element but
     * cannot rename one — and unwrapping them would lose the emphasis.
     */
    private static function normalise(string $html): string
    {
        return preg_replace(
            ['#<b(\s[^>]*)?>#i', '#</b>#i', '#<i(\s[^>]*)?>#i', '#</i>#i', '#<h1(\s[^>]*)?>#i', '#</h1>#i', '#<h[456](\s[^>]*)?>#i', '#</h[456]>#i'],
            ['<strong>', '</strong>', '<em>', '</em>', '<h2>', '</h2>', '<h3>', '</h3>'],
            $html,
        ) ?? $html;
    }

    /** Text typed into a plain textarea: blank lines separate paragraphs. */
    private static function paragraphs(string $text): string
    {
        $blocks = preg_split("/\R\s*\R/u", str_replace("\r\n", "\n", $text)) ?: [$text];

        return implode('', array_map(
            fn (string $block) => '<p>'.nl2br(htmlspecialchars(trim($block), ENT_QUOTES | ENT_HTML5, 'UTF-8'), false).'</p>',
            array_filter($blocks, fn (string $block) => trim($block) !== ''),
        ));
    }

    private static function sanitizer(): HtmlSanitizer
    {
        if (self::$sanitizer) {
            return self::$sanitizer;
        }

        $config = (new HtmlSanitizerConfig)
            ->allowLinkSchemes(['http', 'https', 'mailto'])
            ->allowRelativeLinks(false)
            // Descriptions from the old platform run long; the default cap
            // would truncate them silently mid-sentence.
            ->withMaxInputLength(100_000);

        foreach (self::ALLOWED as $element) {
            $config = $config->allowElement($element, $element === 'a' ? ['href'] : []);
        }

        foreach (self::UNWRAPPED as $element) {
            $config = $config->blockElement($element);
        }

        foreach (self::DROPPED as $element) {
            $config = $config->dropElement($element);
        }

        // A link in a description leaves the event page; it must not be able
        // to reach back into it, and it earns the linked site no ranking.
        $config = $config
            ->forceAttribute('a', 'rel', 'noopener noreferrer nofollow')
            ->forceAttribute('a', 'target', '_blank');

        return self::$sanitizer = new HtmlSanitizer($config);
    }
}
