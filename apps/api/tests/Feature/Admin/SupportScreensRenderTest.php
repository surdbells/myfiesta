<?php

namespace Tests\Feature\Admin;

use App\Enums\PlatformRole;
use App\Enums\Role;
use App\Filament\Resources\DataRequests\DataRequestResource;
use App\Filament\Resources\Disputes\DisputeResource;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\OrganizationIdentityDocuments\OrganizationIdentityDocumentResource;
use App\Filament\Resources\PayoutDetails\PayoutDetailResource;
use App\Filament\Resources\PayoutRequests\PayoutRequestResource;
use App\Filament\Resources\Settlements\SettlementResource;
use App\Filament\Resources\TaxRates\TaxRateResource;
use App\Filament\Resources\Tickets\TicketResource;
use App\Filament\Resources\Users\UserResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every support and money screen, requested the way a browser does — through
 * the panel's middleware, navigation and layout — rather than as a component
 * on its own. Proves the pages render whole, and that a role is refused a
 * screen it does not have rather than shown a broken one.
 */
class SupportScreensRenderTest extends TestCase
{
    use RefreshDatabase, SupportFixtures;

    public function test_an_administrator_opens_every_list_and_record_page(): void
    {
        $organization = $this->organization('Toronto Sound');
        $person = $this->member($organization, Role::Owner, ['name' => 'Ngozi Eze']);
        $event = $this->event($organization, ['title' => 'Highlife Night']);
        $order = $this->paidOrder($event, $this->ticketType($event), 2, ['user_id' => $person->id]);
        $ticket = $order->tickets()->first();

        $this->actingAs($this->staff(PlatformRole::Admin));

        foreach ([
            UserResource::class,
            OrderResource::class,
            TicketResource::class,
            EventResource::class,
            DataRequestResource::class,
            DisputeResource::class,
            OrganizationIdentityDocumentResource::class,
            PayoutDetailResource::class,
            PayoutRequestResource::class,
            SettlementResource::class,
            TaxRateResource::class,
        ] as $resource) {
            $this->get($resource::getUrl('index'))->assertSuccessful();
        }

        $this->get(UserResource::getUrl('view', ['record' => $person]))
            ->assertSuccessful()
            ->assertSee('Ngozi Eze');

        $this->get(OrderResource::getUrl('view', ['record' => $order]))
            ->assertSuccessful()
            ->assertSee($order->reference)
            ->assertDontSee($ticket->code);

        $this->get(TicketResource::getUrl('view', ['record' => $ticket]))
            ->assertSuccessful()
            ->assertDontSee($ticket->code);

        // By its slug, which is what the panel's own links carry, and by its
        // id, which is what survives the organizer renaming it.
        $this->get(EventResource::getUrl('view', ['record' => $event]))
            ->assertSuccessful()
            ->assertSee('Highlife Night');

        $this->get(EventResource::getUrl('view', ['record' => $event->id]))
            ->assertSuccessful()
            ->assertSee('Highlife Night');
    }

    public function test_support_is_refused_the_money_screens_but_not_the_support_ones(): void
    {
        $this->actingAs($this->staff(PlatformRole::Support));

        foreach ([UserResource::class, OrderResource::class, TicketResource::class, EventResource::class,
            DataRequestResource::class, OrganizationIdentityDocumentResource::class, TaxRateResource::class] as $resource) {
            $this->get($resource::getUrl('index'))->assertSuccessful();
        }

        foreach ([DisputeResource::class, PayoutDetailResource::class, PayoutRequestResource::class, SettlementResource::class] as $resource) {
            $this->get($resource::getUrl('index'))->assertForbidden();
        }

        $this->get(TaxRateResource::getUrl('create'))->assertForbidden();
    }

    public function test_a_ticket_buyer_reaches_none_of_it(): void
    {
        $organizer = $this->member($this->organization(), Role::Owner, ['email_verified_at' => now()]);

        foreach ([UserResource::class, OrderResource::class, TicketResource::class, EventResource::class] as $resource) {
            // Each refusal also signs them out of the panel, so sign in again.
            $this->actingAs($organizer)
                ->get($resource::getUrl('index'))
                ->assertForbidden();
        }
    }
}
