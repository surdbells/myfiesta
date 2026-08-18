<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    protected $model = Event::class;

    public function definition(): array
    {
        $title = fake()->catchPhrase();

        return [
            'organization_id' => Organization::factory(),
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'title' => $title,
            'description' => fake()->paragraph(),
            'currency' => 'CAD',
            'starts_at' => now()->addDays(30),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'subdivision' => 'ON',
            'country' => 'CA',
            'status' => 'draft',
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => [
            'status' => 'published',
            'published_at' => now(),
        ]);
    }

    /** An event priced in Naira, which routes to Paystack. */
    public function inLagos(): static
    {
        return $this->state(fn () => [
            'currency' => 'NGN',
            'timezone' => 'Africa/Lagos',
            'city' => 'Lagos',
            'subdivision' => null,
            'country' => 'NG',
        ]);
    }
}
