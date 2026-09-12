<?php

namespace App\Casts;

use App\Support\RichText;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * An attribute that is always safe to render as HTML.
 *
 * Cleaned on the way in rather than on the way out, and at the model rather
 * than in a controller, because a description arrives by more than one road:
 * the organizer API, the legacy importer, seeders, the admin panel. A rule
 * enforced in one controller is a rule the other three skip. Here there is no
 * route to the column that does not pass through it.
 */
class RichHtml implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return RichText::clean($value === null ? null : (string) $value);
    }
}
