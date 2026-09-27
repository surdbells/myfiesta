<?php

namespace App\Services\Settings;

/**
 * Who, in law, sells the ticket to the buyer.
 *
 * It decides whose name heads the receipt and whose tax the service charge
 * is. The ticket's own tax is charged at the event's rate either way; what
 * changes is who that sale belongs to and who files for it.
 */
enum SellerOfRecord: string
{
    /**
     * The organizer sells the ticket; the platform sells the buyer a booking
     * service, which is the service charge.
     *
     * The receipt names the organizer as the seller of the tickets and the
     * platform as the seller of the service. The ticket's tax is the
     * organizer's to account for. The service charge is the platform's own
     * sale, taxed only where the setting says it is.
     */
    case Organizer = 'organizer';

    /**
     * The platform buys from the organizer and sells to the buyer.
     *
     * The receipt names the platform as the seller of everything on it, under
     * the platform's registration numbers. The service charge is then part of
     * the price of the ticket the platform sold, so it is taxed at the event's
     * rate like the rest of it, whatever the separate setting says.
     */
    case Platform = 'platform';

    public function label(): string
    {
        return match ($this) {
            self::Organizer => 'The organizer',
            self::Platform => 'The platform',
        };
    }
}
