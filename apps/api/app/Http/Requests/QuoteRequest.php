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

            // Things sold with a ticket that are not one. Bought alongside,
            // never instead of: an order of bottles and no tickets is a bar
            // tab, and this is not a bar.
            'add_ons' => ['nullable', 'array', 'max:20'],
            'add_ons.*.add_on_id' => ['required', 'uuid'],
            'add_ons.*.quantity' => ['required', 'integer', 'min:1', 'max:50'],
        ];
    }

    /** @return array<string, int> add-on id => quantity */
    public function addOnQuantities(): array
    {
        $out = [];

        foreach ($this->input('add_ons', []) as $item) {
            $id = $item['add_on_id'];
            $out[$id] = ($out[$id] ?? 0) + (int) $item['quantity'];
        }

        return $out;
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
