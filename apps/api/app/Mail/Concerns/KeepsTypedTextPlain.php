<?php

namespace App\Mail\Concerns;

use Closure;
use Illuminate\Mail\Markdown;

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
 * Laravel can escape the bracket that starts a link, but only as one switch
 * for every mail at once, and turning it on everywhere changes how an
 * organizer's own message to their guests reads. So it is on only while one of
 * these renders, and left as it was found afterwards — if somebody does turn it
 * on everywhere later, this must not be what turns it back off.
 */
trait KeepsTypedTextPlain
{
    protected function buildMarkdownHtml($viewData)
    {
        $render = parent::buildMarkdownHtml($viewData);

        return function ($data) use ($render) {
            $wasOn = Closure::bind(fn () => static::$withSecuredEncoding, null, Markdown::class)();

            Markdown::withSecuredEncoding();

            try {
                return $render($data);
            } finally {
                if (! $wasOn) {
                    Markdown::withoutSecuredEncoding();
                }
            }
        };
    }
}
