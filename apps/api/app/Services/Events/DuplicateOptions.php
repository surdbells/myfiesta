<?php

namespace App\Services\Events;

use Carbon\CarbonInterface;

/**
 * What to change on the way to a copy.
 *
 * "Same night, new date" is the copy with nothing changed, and is what the
 * next date of a series is: EventDuplicator copies everything it copies when
 * it is handed none of this. An organizer copying a night to run it again
 * usually changes a little of it — the date, the title for the season, a
 * price that went up, the VIP tier that is not on this time — and making the
 * copy with those already applied saves them editing a draft tier by tier,
 * and missing the one tier they meant to change.
 *
 * Ticket types are named by their key in the blueprint, which is the id of
 * the tier on the event they came from (EventBlueprint). A tier not named
 * comes across as it is. A field not given on a named tier is left as it is;
 * a quantity given as null is unlimited, which is not the same as leaving it.
 */
final class DuplicateOptions
{
    /**
     * @param  array<string, array{include?: bool, name?: string|null, price_amount?: int|null, quantity_available?: int|null}>  $ticketTypes
     */
    public function __construct(
        public readonly ?string $title = null,
        public readonly bool $replacesDescription = false,
        public readonly ?string $description = null,
        public readonly ?CarbonInterface $endsAt = null,
        public readonly array $ticketTypes = [],
        public readonly bool $addOns = true,
        public readonly bool $questions = true,
        public readonly bool $reminders = true,
    ) {}

    /** The copy with nothing changed: a series date, or "same event, new date". */
    public static function everything(): self
    {
        return new self;
    }

    /** The same, with this title, when one was given. */
    public function titled(?string $title): self
    {
        if ($title === null || $title === '') {
            return $this;
        }

        return new self(
            $title,
            $this->replacesDescription,
            $this->description,
            $this->endsAt,
            $this->ticketTypes,
            $this->addOns,
            $this->questions,
            $this->reminders,
        );
    }

    /** Whether this tier comes across at all. */
    public function includes(string $key): bool
    {
        return ($this->ticketTypes[$key]['include'] ?? true) !== false;
    }

    /**
     * A tier as the blueprint has it, with what was asked for applied.
     *
     * @param  array<string, mixed>  $type
     * @return array<string, mixed>
     */
    public function adjust(string $key, array $type): array
    {
        $asked = $this->ticketTypes[$key] ?? [];

        $name = isset($asked['name']) ? trim($asked['name']) : '';

        if ($name !== '') {
            $type['name'] = $name;
        }

        if (isset($asked['price_amount'])) {
            $type['price_amount'] = (int) $asked['price_amount'];
        }

        // Given as null is unlimited; not given is unchanged.
        if (array_key_exists('quantity_available', $asked)) {
            $type['quantity_available'] = $asked['quantity_available'] === null ? null : (int) $asked['quantity_available'];
        }

        return $type;
    }
}
