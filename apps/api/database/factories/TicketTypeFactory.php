<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\TicketType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TicketType>
 */
class TicketTypeFactory extends Factory
{
    protected $model = TicketType::class;

    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'name' => fake()->randomElement(['General Admission', 'Early Bird', 'VIP']),
            'price_amount' => fake()->randomElement([2500, 5000, 10000]),
            'admits' => 1,
            'quantity_available' => 100,
            'max_per_order' => 6,
            'status' => 'on_sale',
        ];
    }

    public function free(): static
    {
        return $this->state(fn () => ['price_amount' => 0]);
    }
}
