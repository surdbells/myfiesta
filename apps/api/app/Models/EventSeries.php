<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The definition behind a repeating event.
 *
 * Holds the rule and nothing else about the event itself — the shape lives on
 * the source event, which is a real one the organizer can look at rather than a
 * hidden template where a wrong price could sit unnoticed.
 */
class EventSeries extends Model
{
    use HasUuids;

    protected $table = 'event_series';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'starts_at' => UtcDateTime::class,
            'generated_through' => UtcDateTime::class,
            'auto_publish' => 'boolean',
            'on_sale_days_before' => 'integer',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * The event occurrences are copied from.
     *
     * @return BelongsTo<Event, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'source_event_id');
    }

    /** @return HasMany<Event, $this> */
    public function occurrences(): HasMany
    {
        return $this->hasMany(Event::class, 'series_id')->orderBy('series_occurs_at');
    }

    /** @return HasMany<EventSeriesException, $this> */
    public function exceptions(): HasMany
    {
        return $this->hasMany(EventSeriesException::class, 'series_id');
    }

    /**
     * Whoever turned on putting each date on sale by itself. Each date goes
     * on sale as them, asked again when it does (events:go-live).
     *
     * @return BelongsTo<User, $this>
     */
    public function autoPublisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auto_publish_by');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * When a date of this series goes on sale by itself: its night less the
     * days the organizer chose, or as soon as it is made when they chose
     * none — and never in the past, so a date made inside its window goes on
     * sale at the next run rather than being dated before it existed. Null
     * when the series does not put its dates on sale.
     */
    public function onSaleAt(DateTimeInterface $night): ?CarbonImmutable
    {
        if (! $this->auto_publish) {
            return null;
        }

        $now = CarbonImmutable::now();

        if ($this->on_sale_days_before === null) {
            return $now;
        }

        // Days on the venue's calendar, so a week before a 9pm night is 9pm
        // there across a clock change too, however the night was handed in.
        $at = CarbonImmutable::instance($night)
            ->setTimezone($this->timezone)
            ->subDays((int) $this->on_sale_days_before)
            ->utc();

        return $at->lessThan($now) ? $now : $at;
    }

    /**
     * The choice the console offered that made this rule: weekly,
     * fortnightly or monthly (SeriesController::ruleFor). Null for a rule
     * written some other way.
     */
    public function frequency(): ?string
    {
        $parts = $this->ruleParts();

        return match ([$parts['FREQ'] ?? null, (int) ($parts['INTERVAL'] ?? 1)]) {
            ['WEEKLY', 1] => 'weekly',
            ['WEEKLY', 2] => 'fortnightly',
            ['MONTHLY', 1] => 'monthly',
            default => null,
        };
    }

    /** @return array<string, string> the rule's parts by name: FREQ, BYDAY, COUNT… */
    public function ruleParts(): array
    {
        $parts = [];

        foreach (explode(';', (string) $this->rrule) as $part) {
            [$name, $value] = array_pad(explode('=', $part, 2), 2, '');

            if ($name !== '') {
                $parts[strtoupper($name)] = $value;
            }
        }

        return $parts;
    }
}
