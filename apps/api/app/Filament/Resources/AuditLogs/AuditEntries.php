<?php

namespace App\Filament\Resources\AuditLogs;

use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Organizations\OrganizationResource;
use App\Filament\Resources\Tickets\TicketResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Arr;
use Throwable;

/**
 * How an audit entry reads on screen.
 *
 * Metadata is shown as the JSON it was written as, with the value of any key
 * that names a credential, a ticket code or an account number replaced — the
 * trail is not supposed to hold them, and if some caller ever writes one this
 * screen is not where it gets read.
 */
final class AuditEntries
{
    public const NEVER_SHOWN = '/token|password|secret|ticket_code|scanned_code|account_number/i';

    /**
     * The keys callers record an amount under, in minor units: a settlement's
     * or refund's "amount", a paid request's "requested" and "paid", a price
     * change's "price_amount" either side. Not "refunded" or "failed", which
     * the cancellation entry uses for counts.
     */
    private const MONEY_KEYS = '/^(amount|price|total|balance|requested|paid|owed)$|_amount$/';

    /**
     * Subjects with a page of their own in the admin, and the resource that
     * has it. An organization's is where what an entry about one records — a
     * suspension, a change to its team, staff acting as it — can be read
     * alongside everything else about it, and acted on.
     */
    private const PAGES = [
        Order::class => OrderResource::class,
        Event::class => EventResource::class,
        Ticket::class => TicketResource::class,
        User::class => UserResource::class,
        Organization::class => OrganizationResource::class,
    ];

    public static function redact(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && preg_match(self::NEVER_SHOWN, $key) === 1) {
            return '[not shown]';
        }

        if (is_array($value)) {
            $out = [];

            foreach ($value as $k => $v) {
                $out[$k] = self::redact($v, is_string($k) ? $k : null);
            }

            return $out;
        }

        return $value;
    }

    /**
     * The same metadata with each amount written as money: 500000 beside
     * "NGN" becomes "₦5,000".
     *
     * Amounts are recorded in minor units beside the currency they are in,
     * which is right for the record and wrong for somebody reading it: a
     * settlement of "amount: 500000" reads as half a million. Only a whole
     * number under a key that names an amount is rewritten, and only with a
     * currency at its level or above it — "tickets: 2" is a count, and stays
     * one.
     *
     * @param  array<array-key, mixed>  $metadata
     * @return array<array-key, mixed>
     */
    public static function withMoneyWritten(array $metadata, ?string $currency = null): array
    {
        $here = is_string($metadata['currency'] ?? null) && preg_match('/^[A-Z]{3}$/', strtoupper($metadata['currency'])) === 1
            ? strtoupper($metadata['currency'])
            : $currency;

        $out = [];

        foreach ($metadata as $key => $value) {
            $out[$key] = match (true) {
                is_array($value) => self::withMoneyWritten($value, $here),
                is_int($value) && $here !== null && is_string($key) && preg_match(self::MONEY_KEYS, $key) === 1 => Money::of($value, $here)->format(),
                default => $value,
            };
        }

        return $out;
    }

    /**
     * Each amount an entry records, as money: "amount: ₦5,000",
     * "before › price amount: $15.00".
     *
     * For the full entry, whose metadata is shown exactly as it was written;
     * this says what the figures in it come to, beside it rather than instead
     * of it.
     *
     * @return list<string>
     */
    public static function amounts(?array $metadata): array
    {
        if ($metadata === null || $metadata === []) {
            return [];
        }

        $shown = (array) self::redact($metadata);
        $recorded = Arr::dot($shown);
        $amounts = [];

        foreach (Arr::dot(self::withMoneyWritten($shown)) as $path => $value) {
            if ($value !== ($recorded[$path] ?? null)) {
                $amounts[] = str_replace(['.', '_'], [' › ', ' '], (string) $path).': '.$value;
            }
        }

        return $amounts;
    }

    public static function json(?array $metadata): string
    {
        if ($metadata === null || $metadata === []) {
            return 'No details were recorded.';
        }

        return (string) json_encode(self::redact($metadata), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** "Order · 1f0c2a9e", or "—" for an entry about nothing in particular. */
    public static function subject(AuditLog $entry): string
    {
        if ($entry->subject_type === null) {
            return '—';
        }

        return class_basename($entry->subject_type).($entry->subject_id ? ' · '.substr((string) $entry->subject_id, 0, 8) : '');
    }

    public static function subjectUrl(AuditLog $entry): ?string
    {
        $resource = self::PAGES[$entry->subject_type] ?? null;

        if ($resource === null || $entry->subject_id === null || ! class_exists($resource)) {
            return null;
        }

        try {
            return $resource::canViewAny() ? $resource::getUrl('view', ['record' => $entry->subject_id]) : null;
        } catch (Throwable) {
            return null;
        }
    }

    public static function isImpersonation(AuditLog $entry): bool
    {
        return is_array($entry->metadata) && array_key_exists('impersonating', $entry->metadata);
    }
}
