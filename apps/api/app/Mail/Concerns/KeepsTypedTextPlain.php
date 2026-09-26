<?php

namespace App\Mail\Concerns;

use Closure;
use Illuminate\Mail\Markdown;
use Illuminate\Support\EncodedHtmlString;

/**
 * Whatever somebody typed stays words on the page, never a link.
 *
 * A Markdown mail escapes each value for HTML and then reads the whole thing
 * as Markdown, and HTML escaping leaves square brackets alone. So a name like
 * "[Cancel the change](https://…)" — or an address with the same thing in
 * quotes before the @, which counts as a valid address — arrives as a working
 * link, in an email that comes from us. In the emails that warn somebody their
 * account is being moved, that link would sit beside the real advice, written
 * by the very person the email is warning them about.
 *
 * So while one of these renders, every value is escaped for HTML as usual and
 * then for Markdown: a backslash before each square bracket, so it cannot open
 * a link or an image, and a second backslash before each one that was typed.
 * Without that second part, "\[Cancel](https://…)" comes out as
 * "\\[Cancel](https://…)", which Markdown reads as a plain backslash followed
 * by a working link.
 *
 * Laravel has a switch that escapes the bracket, but not the backslash, and
 * while it is on it puts its own escaping in place of this one. It is also one
 * switch for every mail at once, and turning it on everywhere changes how an
 * organizer's own message to their guests reads. So it is off only while one
 * of these renders, and left as it was found afterwards — if somebody does turn
 * it on everywhere later, this must not be what turns it back off.
 *
 * Only the HTML part. The plain-text part is never read as Markdown, so it
 * keeps the usual escaping and a typed backslash shows once.
 */
trait KeepsTypedTextPlain
{
    protected function buildMarkdownHtml($viewData)
    {
        $render = parent::buildMarkdownHtml($viewData);

        return function ($data) use ($render) {
            $wasOn = Closure::bind(fn () => static::$withSecuredEncoding, null, Markdown::class)();

            Markdown::withoutSecuredEncoding();
            EncodedHtmlString::encodeUsing(self::plainInMarkdown(...));

            try {
                return $render($data);
            } finally {
                EncodedHtmlString::flushState();

                if ($wasOn) {
                    Markdown::withSecuredEncoding();
                }
            }
        };
    }

    /**
     * One value, as text that Markdown will leave as text.
     *
     * The typed backslashes are doubled before the brackets get theirs, so the
     * ones added here are not doubled as well.
     */
    private static function plainInMarkdown(mixed $value, bool $doubleEncode = true): string
    {
        return str_replace(['\\', '['], ['\\\\', '\\['], e($value, $doubleEncode));
    }
}
