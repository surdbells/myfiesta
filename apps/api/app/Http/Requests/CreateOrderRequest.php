<?php

namespace App\Http\Requests;

class CreateOrderRequest extends QuoteRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'buyer.name' => ['required', 'string', 'max:120'],
            'buyer.email' => ['required', 'email:rfc', 'max:255'],
            'buyer.phone' => ['nullable', 'string', 'max:32'],
        ]);
    }
}
