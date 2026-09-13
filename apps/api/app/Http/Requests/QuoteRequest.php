<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Note what cannot be sent: a price, a total, a tax amount, a discount value.
 *
 * The client says which tickets and how many. Everything with a currency sign
 * in front of it is computed server-side from rows in the database. The
 * previous platform accepted `total` from the request body and passed it
 * straight to Stripe.
 */
class QuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.ticket_type_id' => ['required', 'uuid'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:50'],
            'code' => ['nullable', 'string', 'max:64'],
            // A presale code, opening tiers that are hidden or not yet on sale.
            'access_code' => ['nullable', 'string', 'max:64'],
            'ref' => ['nullable', 'string', 'max:64'],
        ];
    }

    /** @return array<string, int> ticket type id => quantity */
    public function quantities(): array
    {
        $out = [];

        foreach ($this->input('items', []) as $item) {
            $id = $item['ticket_type_id'];
            // Summed rather than overwritten: a client sending the same type
            // twice means three plus two, not two.
            $out[$id] = ($out[$id] ?? 0) + (int) $item['quantity'];
        }

        return $out;
    }
}
