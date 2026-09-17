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

    protected $guarded = ['id'];

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

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function ticketTypes(): HasMany
    {
        return $this->hasMany(TicketType::class);
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(EventSeries::class, 'series_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(EventMessage::class)->latest('created_at');
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(EventReminder::class)->orderByDesc('offset_minutes');
    }

    public function images(): HasMany
    {
        return $this->hasMany(EventImage::class);
    }

    /** The one picture that sells the link. */
    public function banner(): HasOne
    {
        return $this->hasOne(EventImage::class)->where('kind', 'banner');
    }

    public function gallery(): HasMany
    {
        return $this->hasMany(EventImage::class)
            ->where('kind', 'gallery')
            ->orderBy('position')
            ->orderBy('created_at');
    }

    /** Sold with a ticket and admitting nobody: a table, a bottle, a shirt. */
    public function addOns(): HasMany
    {
        return $this->hasMany(AddOn::class)->orderBy('sort_order')->orderBy('created_at');
    }

    /** What this event asks the people coming to it, in the order it asks. */
    public function questions(): HasMany
    {
        return $this->hasMany(EventQuestion::class)->orderBy('sort_order')->orderBy('created_at');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

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
