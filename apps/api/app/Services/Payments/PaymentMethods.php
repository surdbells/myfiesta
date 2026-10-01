<?php

namespace App\Services\Payments;

/**
 * A payment method in words, from the processor's own word for it.
 *
 * Stripe says card, klarna, affirm, link; staff read "Card", "Klarna (paid
 * later)". A word not listed here is shown as it came, capitalised, rather
 * than hidden: a method somebody switched on in the dashboard without this
 * knowing is exactly what support should be able to see.
 */
final class PaymentMethods
{
    private const LABELS = [
        'card' => 'Card',
        'link' => 'Link',
        'klarna' => 'Klarna (paid later)',
        'affirm' => 'Affirm (paid later)',
        'acss_debit' => 'Pre-authorized debit',
        'customer_balance' => 'Bank transfer',
    ];

    public static function label(?string $method): ?string
    {
        if ($method === null || $method === '') {
            return null;
        }

        return self::LABELS[$method] ?? ucfirst(str_replace('_', ' ', $method));
    }
}
