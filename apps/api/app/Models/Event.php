<?php

namespace App\Models;

use App\Casts\RichHtml;
use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Event extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * Words the public site already answers at its root.
     *
     * Event pages live at myfiesta.ca/{slug}, and the site's own pages sit
     * above that wildcard — so an event titled "Help" was given the slug
     * `help` and could never be reached: the help page answered instead. A
     * reserved word is treated as taken and gets a suffix like any collision.
     * Keep in step with the top-level routes in apps/web and with SITE_PAGES
     * in the phone app; ReservedSlugMirrorTest fails when they drift.
     */
    public const RESERVED_SLUGS = [
        'events', 'help', 'terms', 'privacy', 'contact', 'refunds', 'order',
        'tickets', 'register', 'sign-in', 'login', 'o', 'embed', 'embed-js',
        'robots-txt', 'sitemap-xml',
    ];

    protected $guarded = ['id'];

    /** Whether a slug is free to give a new event. */
    public static function slugIsTaken(string $slug, bool $withTrashed = false): bool
    {
        return in_array($slug, self::RESERVED_SLUGS, true)
            || ($withTrashed ? self::withTrashed() : self::query())->where('slug', $slug)->exists();
    }

    protected function casts(): array
    {
        return [
            // Sanitized HTML, cleaned on every write whichever road it came by
            // — the organizer API, the legacy importer, seeders, the admin.
            'description' => RichHtml::class,
            // UtcDateTime rather than 'datetime': Eloquent stores the wall
            // clock of whatever instance it is given and drops the zone, so a
            // Carbon in the venue's zone lands in the column hours out.
            'starts_at' => UtcDateTime::class,
            'ends_at' => UtcDateTime::class,
            'published_at' => 'datetime',
            'cancelled_at' => UtcDateTime::class,
            // The slot the rule scheduled, distinct from starts_at so an
            // occurrence an organizer moved is still recognised as filled.
            'series_occurs_at' => UtcDateTime::class,
            'id_required' => 'boolean',
            'is_featured' => 'boolean',
        ];
    }

    /** Slugs are the shareable link and are routed at the root. */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Venue, $this> */
    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    /** @return HasMany<TicketType, $this> */
    public function ticketTypes(): HasMany
    {
        return $this->hasMany(TicketType::class);
    }

    /** @return BelongsTo<EventSeries, $this> */
    public function series(): BelongsTo
    {
        return $this->belongsTo(EventSeries::class, 'series_id');
    }

    /** @return HasMany<EventMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(EventMessage::class)->latest('created_at');
    }

    /** @return HasMany<EventReminder, $this> */
    public function reminders(): HasMany
    {
        return $this->hasMany(EventReminder::class)->orderByDesc('offset_minutes');
    }

    /** @return HasMany<EventImage, $this> */
    public function images(): HasMany
    {
        return $this->hasMany(EventImage::class);
    }

    /**
     * The one picture that sells the link.
     *
     * @return HasOne<EventImage, $this>
     */
    public function banner(): HasOne
    {
        return $this->hasOne(EventImage::class)->where('kind', 'banner');
    }

    /** @return HasMany<EventImage, $this> */
    public function gallery(): HasMany
    {
        return $this->hasMany(EventImage::class)
            ->where('kind', 'gallery')
            ->orderBy('position')
            ->orderBy('created_at');
    }

    /**
     * Sold with a ticket and admitting nobody: a table, a bottle, a shirt.
     *
     * @return HasMany<AddOn, $this>
     */
    public function addOns(): HasMany
    {
        return $this->hasMany(AddOn::class)->orderBy('sort_order')->orderBy('created_at');
    }

    /**
     * What this event asks the people coming to it, in the order it asks.
     *
     * @return HasMany<EventQuestion, $this>
     */
    public function questions(): HasMany
    {
        return $this->hasMany(EventQuestion::class)->orderBy('sort_order')->orderBy('created_at');
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** @return HasMany<Ticket, $this> */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published');
    }

    /**
     * Postgres full-text search over the generated tsvector column.
     *
     * This is why no separate search service is in the stack.
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->whereRaw(
            "search_vector @@ plainto_tsquery('simple', ?)",
            [$term]
        )->orderByRaw(
            "ts_rank(search_vector, plainto_tsquery('simple', ?)) DESC",
            [$term]
        );
    }
}
