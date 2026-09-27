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
