<?php

namespace Tests\Feature\Admin;

use App\Contracts\Payments\PaymentGateway;
use App\Contracts\Payments\PaymentGatewayRegistry;
use App\Contracts\Payments\RefundResult;
use App\Enums\PlatformRole;
use App\Enums\Role;
use App\Models\Event;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Mockery;

/**
 * What the support-screen tests stand on: staff in each role, an organizer
 * selling in each currency, and paid orders with real tickets.
 *
 * Orders are written directly rather than through checkout, so these tests
 * exercise the admin screens and not the payment path in front of them.
 */
trait SupportFixtures
{
    /** Whether the fake processor gives the money back. */
    protected bool $gatewayRefunds = true;

    protected function staff(PlatformRole $role, array $attributes = []): User
    {
        return User::factory()->create([
            'platform_role' => $role,
            'email_verified_at' => now(),
            ...$attributes,
        ]);
    }

    protected function actAs(User $user): User
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $user;
    }

    protected function organization(string $name = 'Lagos Nights'): Organization
    {
        return Organization::create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'contact_email' => 'hello@'.Str::slug($name).'.test',
        ]);
    }

    protected function member(Organization $organization, Role $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);

        $organization->members()->attach($user->id, [
            'id' => (string) Str::uuid(),
            'role' => $role->value,
            'accepted_at' => now(),
        ]);

        return $user;
    }

    protected function event(Organization $organization, array $attributes = []): Event
    {
        $lagos = ($attributes['currency'] ?? 'CAD') === 'NGN';

        return Event::create([
            'organization_id' => $organization->id,
            'slug' => 'event-'.Str::lower(Str::random(8)),
            'title' => 'Afro Fest',
            'currency' => 'CAD',
            'starts_at' => now()->addMonth(),
            'timezone' => $lagos ? 'Africa/Lagos' : 'America/Toronto',
            'city' => $lagos ? 'Lagos' : 'Toronto',
            'subdivision' => $lagos ? null : 'ON',
            'country' => $lagos ? 'NG' : 'CA',
            'status' => 'published',
            'published_at' => now(),
            ...$attributes,
        ]);
    }

    protected function ticketType(Event $event, array $attributes = []): TicketType
    {
        return TicketType::create([
            'event_id' => $event->id,
            'name' => 'General',
            'price_amount' => 5000,
            'quantity_available' => 100,
            'status' => 'on_sale',
            ...$attributes,
        ]);
    }

    /**
     * A paid order of $quantity tickets at the type's price, with 13% tax on
     * top, as checkout would have written it.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function paidOrder(Event $event, TicketType $type, int $quantity = 2, array $attributes = []): Order
    {
        $subtotal = $type->price_amount * $quantity;
        $tax = (int) round($subtotal * 0.13);
        $door = ($attributes['channel'] ?? 'online') === 'door';

        $order = Order::create([
            'organization_id' => $event->organization_id,
            'event_id' => $event->id,
            'buyer_email' => 'ada@example.com',
            'buyer_name' => 'Ada Okafor',
            'currency' => $event->currency,
            'subtotal_amount' => $subtotal,
            'discount_amount' => 0,
            'tax_amount' => $tax,
            'net_revenue_amount' => $subtotal,
            'service_charge_amount' => 0,
            'total_amount' => $subtotal + $tax,
            'gateway' => $door ? null : ($event->currency === 'NGN' ? 'paystack' : 'stripe'),
            'gateway_reference' => $door ? null : 'pi_'.Str::random(10),
            'payment_method' => $door ? 'cash' : null,
            'status' => 'paid',
            'paid_at' => now(),
            ...$attributes,
        ]);

        $order->lines()->create([
            'ticket_type_id' => $type->id,
            'name' => $type->name,
            'unit_price_amount' => $type->price_amount,
            'quantity' => $quantity,
            'line_total_amount' => $subtotal,
        ]);

        for ($i = 0; $i < $quantity; $i++) {
            Ticket::create([
                'code' => Ticket::generateCode(),
                'event_id' => $event->id,
                'ticket_type_id' => $type->id,
                'order_id' => $order->id,
                'owner_user_id' => $order->user_id,
                'owner_email' => $order->buyer_email,
                'holder_name' => $order->buyer_name,
                'admits' => 1,
                'status' => 'valid',
            ]);
        }

        return $order->refresh();
    }

    /**
     * A processor that answers refunds as the test says.
     *
     * A mock of the contract rather than a class implementing it, so a method
     * added to the contract elsewhere does not break these tests.
     */
    protected function fakeGateways(): void
    {
        $registry = new PaymentGatewayRegistry;

        foreach (['stripe', 'paystack'] as $name) {
            $gateway = Mockery::mock(PaymentGateway::class);
            $gateway->shouldReceive('name')->andReturn($name);
            $gateway->shouldReceive('supports')->andReturn(true);
            $gateway->shouldReceive('refund')->andReturnUsing(fn (Order $order, int $amount) => $this->gatewayRefunds
                ? new RefundResult(true, 're_'.Str::random(8), $amount, $order->currency)
                : RefundResult::failed('Card account closed'));

            $registry->register($gateway);
        }

        $this->app->instance(PaymentGatewayRegistry::class, $registry);
    }
}
