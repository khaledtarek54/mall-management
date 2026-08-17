<?php

namespace App\Support;

use App\Http\Resources\Api\V1\NotificationResource;
use App\Models\Announcement;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\TenantRequest;
use App\Models\TenantSalesDeclaration;
use App\Notifications\Channels\PushChannel;

/**
 * **Where a notification opens in the MOBILE app — derived from {@see NotificationTargets}, not
 * guessed from the class name.**
 *
 * The web panels have had a real destination per notification since `NotificationTargets` landed.
 * The app never did: it inferred one by matching SUBSTRINGS of the PHP class name
 * (`contains('invoice')`, `contains('maintenance')`, …) against a table it maintained itself. That
 * contract is unwritable on this side and untestable on either, and it failed exactly as you would
 * expect:
 *
 *   - it read `maintenanceId` while every tenant-request notification has ALWAYS emitted
 *     `request_id`, so the two highest-frequency tenant alerts — a status change and a staff
 *     comment — deep-linked to nothing, in the inbox and on the push;
 *   - `LateFeeAppliedNotification` and `LeaseExpiryApproachingNotification` match none of its
 *     keywords, so both fell through to "other" while carrying a perfectly good `invoice_id` /
 *     `lease_id`.
 *
 * So the destination now ships ON THE WIRE, as one `link` key, from the registry that already
 * states it. Adding a notification cannot forget it: `NotificationDeepLinkConformanceTest` already
 * fails the build when a notification with a `toDatabase()` has no `NotificationTargets` row, and
 * when a row names a payload key the notification does not emit. This class adds no second thing
 * to remember — it reads what that gate already guarantees.
 *
 * **Absence is meaningful.** {@see self::TARGETS} lists only the records the app has a SCREEN for.
 * A work order, a vendor document, a violation or a marketing post resolves to `null` — no link,
 * rather than a link to a route the app cannot open. That is the honest answer, and it is
 * self-documenting: the day the app grows a screen, one line here lights up every notification
 * that points at it.
 *
 * @see PushChannel  ships this to FCM (outbound — never passes through CamelCaseResponseKeys)
 * @see NotificationResource  ships it on the inbox, so a push tap and an inbox tap agree
 */
final class MobileNotificationLink
{
    /**
     * Record class => the app's route target.
     *
     * These strings are a WIRE CONTRACT: they are the app's `NotifTarget` enum
     * (`invoice` → `/invoices/{id}`, `request` → `/requests/{id}`, …). Renaming one is a breaking
     * change for a released client, so it needs a version bump, not a tidy-up.
     *
     * Deliberately absent, each because the app has no screen to open:
     *   - `MarketingPost` — the retailer's own submissions have no mobile surface yet, though the
     *     mall's approval/rejection push already reaches them. First thing to add when it does.
     *   - `FacilityWorkOrder`, `ServicePlan`, `InventoryItem`, `Vendor`, `OwnerRequest`,
     *     `OwnerStatement` — staff/owner records; the tenant app has no such screens by design.
     *   - `Violation`, `Lease`, `Tenant` — reachable in the portal, not (yet) addressable by a
     *     route in the app.
     *
     * @var array<class-string, string>
     */
    private const TARGETS = [
        Invoice::class => 'invoice',
        Payment::class => 'payment',
        TenantRequest::class => 'request',
        TenantSalesDeclaration::class => 'sales',
        Announcement::class => 'announcement',
    ];

    /**
     * The app's deep link for this notification, or null when there is nowhere to send them.
     *
     * @param  class-string  $notification  the notification CLASS — the destination is a property
     *                                      of the KIND of alert, which is also all a backfill over
     *                                      stored rows would have.
     * @param  array<string, mixed>  $payload  the notification's own `toDatabase()` output; the id
     *                                         is read from here so `NotificationTargets`' payload
     *                                         key stays the single description of where it lives.
     * @return array{target: string, id: int}|null
     */
    public static function for(string $notification, array $payload): ?array
    {
        $record = NotificationTargets::TARGETS[$notification]['record'] ?? null;

        if ($record === null) {
            return null;
        }

        [$class, $payloadKey] = $record;

        $target = self::TARGETS[$class] ?? null;

        if ($target === null) {
            return null;
        }

        $id = $payload[$payloadKey] ?? null;

        // A row whose id is missing is a link to `/invoices/null`. No link is better: the app
        // renders the alert as unclickable rather than navigating somewhere that 404s.
        if (! is_numeric($id)) {
            return null;
        }

        return ['target' => $target, 'id' => (int) $id];
    }
}
