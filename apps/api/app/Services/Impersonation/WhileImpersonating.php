<?php

namespace App\Services\Impersonation;

use App\Enums\Permission;
use App\Enums\PlatformRole;
use App\Enums\Role;
use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DoorController;
use App\Http\Controllers\Api\EmailVerificationController;
use App\Http\Controllers\Api\InvitationController;
use App\Http\Controllers\Api\Organizer\BrandController;
use App\Http\Controllers\Api\Organizer\CampaignController;
use App\Http\Controllers\Api\Organizer\CodeBatchController;
use App\Http\Controllers\Api\Organizer\DoorPassController;
use App\Http\Controllers\Api\Organizer\EventController;
use App\Http\Controllers\Api\Organizer\GuestController;
use App\Http\Controllers\Api\Organizer\IntegrationController;
use App\Http\Controllers\Api\Organizer\IssuedTicketController;
use App\Http\Controllers\Api\Organizer\MessageController;
use App\Http\Controllers\Api\Organizer\OrderController;
use App\Http\Controllers\Api\Organizer\PayoutController;
use App\Http\Controllers\Api\Organizer\RefundController;
use App\Http\Controllers\Api\Organizer\SeriesController;
use App\Http\Controllers\Api\Organizer\TeamController;
use App\Http\Controllers\Api\Organizer\WaitlistController;
use App\Models\Event;
use App\Services\Follows\Announcements;
use Illuminate\Http\Request;

/**
 * What staff may not do while acting as an organization.
 *
 * A session acts as an owner, less the things below. Two layers, on purpose:
 *
 * 1. The permissions in WITHHELD are removed from what User::hasPermissionIn()
 *    and permissionsIn() answer for the session. That is the authority: every
 *    policy and controller already asks those two methods, so a withheld
 *    permission is refused everywhere it is checked, including endpoints
 *    written after this one, and the console hides the buttons because the
 *    list it is sent no longer has them.
 *
 * 2. REFUSED names the endpoints behind those permissions, and the few with
 *    no permission of their own (exports, sending mail to buyers, the staff
 *    member's own account), so ImpersonationBoundary can refuse them
 *    before a controller runs — with one message that says why, rather than
 *    a controller's "Only owners can…" to somebody who is, on paper, one.
 *    A controller renamed out from under this list still refuses through
 *    layer 1; ImpersonationTest checks every entry names a real route.
 *
 *    REFUSED_WHEN names the few refused only for some requests or some
 *    staff — a send that depends on what was asked for, and the payouts
 *    statement for a role the admin panel keeps it from — decided in
 *    refusalFor() with the request and the staff member's role in hand.
 */
final class WhileImpersonating
{
    /**
     * The owner's permissions staff do not get.
     *
     * Finance's jobs — where money goes and asking for it — and the owner's —
     * the team, the brand, the integrations — plus anything that cannot be
     * walked back: cancelling or deleting an event, refunding, and admitting
     * somebody at a door. Refunds in particular are made from the admin panel,
     * where they are recorded as the platform acting, not the organization.
     *
     * @var list<Permission>
     */
    public const WITHHELD = [
        Permission::PayoutsDestination,
        Permission::PayoutsRequest,
        Permission::TeamManage,
        Permission::IntegrationsManage,
        Permission::BrandManage,
        Permission::EventsCancel,
        Permission::EventsDelete,
        Permission::RefundsProcess,
        Permission::DoorScan,
    ];

    /** Why each withheld permission is withheld, in words for the staff member. */
    public static function reasonFor(Permission $permission): string
    {
        return match ($permission) {
            Permission::PayoutsDestination => 'Where payouts are sent is the owners’ decision alone. Staff cannot change it while acting as the organization.',
            Permission::PayoutsRequest => 'Payouts are asked for by the organization’s own owners or finance members, never by staff acting for them.',
            Permission::TeamManage => 'Who is on the team, and in what role, is for the organization’s owners. Staff cannot see or change the team while acting as the organization.',
            Permission::IntegrationsManage => 'Webhooks and API keys send buyers’ details to other systems. Only the organization’s owners connect them.',
            Permission::BrandManage => 'The organization’s name and public identity are its own to change.',
            Permission::EventsCancel, Permission::EventsDelete => 'Cancelling an event or removing dates cannot be walked back, so it is left to the organization.',
            Permission::RefundsProcess => 'Refunds are made from the admin panel, where they are recorded as a staff action — not from inside the organization’s console.',
            Permission::DoorScan => 'The door is for the people at the door. Staff cannot scan, sell or issue door passes while acting as the organization.',
            default => 'Staff cannot do this while acting as the organization.',
        };
    }

    /** Things with no permission of their own. */
    public const EXPORTS = 'Exports are not available while acting as an organization: a file of buyers’ details leaves the platform, and helping an organizer does not need one.';

    /**
     * Sending to buyers. Not a withheld permission: messages.send also opens
     * the history of what was sent and the waitlist, which staff helping with
     * "did my message go out?" need to read. Only the sends are refused.
     */
    public const SENDS = 'Messages go to buyers in the organization’s name and cannot be called back once sent, so staff do not send them while acting as the organization.';

    /** Publishing for the first time is a send too: see Announcements. */
    public const ANNOUNCES = 'Publishing this event for the first time emails everybody who follows the organization, in its name, and that cannot be called back — so the organization publishes it. Everything up to that is yours to prepare.';

    /**
     * The payouts statement names where the money goes (bank, account holder,
     * last four digits, Interac address), every settlement sent and every
     * payout asked for. The admin panel keeps all three from support
     * (PayoutDetailResource, SettlementResource, PayoutRequestResource), and
     * acting as the organization is not a way round that.
     */
    public const PAYOUTS = 'Where this organization’s payouts go, what has been sent and what it has asked for are for finance and administrators. Your staff role does not reach them here any more than it does in the admin panel.';

    public const OWN_ACCOUNT = 'Your own account is out of reach while you act as an organization. End the staff session to return to it.';

    /**
     * Endpoints refused outright, by controller action.
     *
     * @var array<string, Permission|string>
     */
    public const REFUSED = [
        // Where the money goes, and asking for it.
        PayoutController::class.'@update' => Permission::PayoutsDestination,
        PayoutController::class.'@requestPayout' => Permission::PayoutsRequest,
        PayoutController::class.'@cancelRequest' => Permission::PayoutsRequest,

        // The team.
        TeamController::class.'@index' => Permission::TeamManage,
        TeamController::class.'@invite' => Permission::TeamManage,
        TeamController::class.'@updateRole' => Permission::TeamManage,
        TeamController::class.'@remove' => Permission::TeamManage,
        TeamController::class.'@revoke' => Permission::TeamManage,

        // Other systems.
        IntegrationController::class.'@index' => Permission::IntegrationsManage,
        IntegrationController::class.'@storeEndpoint' => Permission::IntegrationsManage,
        IntegrationController::class.'@updateEndpoint' => Permission::IntegrationsManage,
        IntegrationController::class.'@destroyEndpoint' => Permission::IntegrationsManage,
        IntegrationController::class.'@test' => Permission::IntegrationsManage,
        IntegrationController::class.'@deliveries' => Permission::IntegrationsManage,
        IntegrationController::class.'@storeKey' => Permission::IntegrationsManage,
        IntegrationController::class.'@revokeKey' => Permission::IntegrationsManage,

        // The organization's public face. Reading it stays open.
        BrandController::class.'@update' => Permission::BrandManage,
        BrandController::class.'@storeLogo' => Permission::BrandManage,
        BrandController::class.'@destroyLogo' => Permission::BrandManage,

        // What cannot be walked back.
        EventController::class.'@cancellationPreview' => Permission::EventsCancel,
        EventController::class.'@cancel' => Permission::EventsCancel,
        SeriesController::class.'@skip' => Permission::EventsDelete,
        SeriesController::class.'@destroy' => Permission::EventsDelete,
        RefundController::class.'@store' => Permission::RefundsProcess,

        // The door, and the passes that outlive this session.
        DoorController::class.'@scan' => Permission::DoorScan,
        DoorController::class.'@list' => Permission::DoorScan,
        DoorController::class.'@sync' => Permission::DoorScan,
        DoorController::class.'@sellable' => Permission::DoorScan,
        DoorController::class.'@quote' => Permission::DoorScan,
        DoorController::class.'@sell' => Permission::DoorScan,
        DoorController::class.'@takings' => Permission::DoorScan,
        DoorPassController::class.'@index' => Permission::DoorScan,
        DoorPassController::class.'@store' => Permission::DoorScan,
        DoorPassController::class.'@destroy' => Permission::DoorScan,

        // Files of personal data.
        OrderController::class.'@export' => self::EXPORTS,
        GuestController::class.'@export' => self::EXPORTS,
        CodeBatchController::class.'@export' => self::EXPORTS,

        // Mail to buyers that cannot be called back. Reading what was sent,
        // the waitlist, and cancelling a campaign not yet sent stay open.
        MessageController::class.'@store' => self::SENDS,
        CampaignController::class.'@store' => self::SENDS,
        CampaignController::class.'@update' => self::SENDS,
        WaitlistController::class.'@notify' => self::SENDS,

        // The staff member's own account. Signing out is not here: it ends
        // the session, see ImpersonationBoundary.
        AuthController::class.'@me' => self::OWN_ACCOUNT,
        AccountController::class.'@updateProfile' => self::OWN_ACCOUNT,
        AccountController::class.'@changePassword' => self::OWN_ACCOUNT,
        AccountController::class.'@requestEmailChange' => self::OWN_ACCOUNT,
        // Invokable: the router names it by the class alone.
        EmailVerificationController::class => self::OWN_ACCOUNT,
        InvitationController::class.'@accept' => self::OWN_ACCOUNT,
    ];

    /**
     * Endpoints refused only for some requests, or some staff. The rule for
     * each is in refusalFor(); this list is what keeps them honest in
     * ImpersonationTest when a controller is renamed.
     *
     * @var list<string>
     */
    public const REFUSED_WHEN = [
        // Unless the staff member's own role reads payout details.
        PayoutController::class.'@index',
        // When publishing would email the organization's followers.
        EventController::class.'@publish',
        // When the tickets would be emailed.
        IssuedTicketController::class.'@store',
    ];

    /**
     * Whether this staff role reaches the payouts statement: the same roles
     * the admin panel shows payout details, settlements and payout requests.
     */
    public static function seesPayouts(?PlatformRole $role): bool
    {
        return $role !== null && $role->canReadSensitiveData() && $role->canSettle();
    }

    /**
     * An owner's permissions, less the withheld ones.
     *
     * @param  list<Permission>  $held
     * @return list<Permission>
     */
    public static function withhold(array $held): array
    {
        return array_values(array_filter(
            $held,
            fn (Permission $permission) => ! in_array($permission, self::WITHHELD, true),
        ));
    }

    /**
     * What a session may do, by name — the list the console is sent.
     *
     * @return list<string>
     */
    public static function permissionNames(): array
    {
        return array_map(fn (Permission $p) => $p->value, self::withhold(Permission::forRole(Role::Owner)));
    }

    /**
     * Why this request is refused, or null when it is not.
     *
     * By controller action first (REFUSED), then the ones that depend on
     * what was asked for or who is asking (REFUSED_WHEN). Without a request
     * only REFUSED is consulted; with one, a missing role reads as a role
     * that reaches nothing.
     */
    public static function refusalFor(?string $action, ?Request $request = null, ?PlatformRole $staffRole = null): ?string
    {
        if ($action === null) {
            return null;
        }

        if (array_key_exists($action, self::REFUSED)) {
            $refused = self::REFUSED[$action];

            return $refused instanceof Permission ? self::reasonFor($refused) : $refused;
        }

        if ($request === null) {
            return null;
        }

        return match ($action) {
            PayoutController::class.'@index' => self::seesPayouts($staffRole) ? null : self::PAYOUTS,
            EventController::class.'@publish' => self::wouldAnnounce($request) ? self::ANNOUNCES : null,
            IssuedTicketController::class.'@store' => $request->boolean('send_email') ? self::SENDS : null,
            default => null,
        };
    }

    /**
     * Whether this publish request would email the organization's followers.
     *
     * Taking an event down, and putting back up one already announced, send
     * nothing and stay open: support unpublishing to fix a typo must be able
     * to put it back.
     */
    private static function wouldAnnounce(Request $request): bool
    {
        if ($request->input('status') !== 'published') {
            return false;
        }

        // Bound or not yet, depending on where in the stack this is asked.
        $event = $request->route('event');
        $event = $event instanceof Event ? $event : (is_string($event) ? Event::query()->find($event) : null);

        return $event !== null && app(Announcements::class)->wouldAnnounce($event);
    }
}
