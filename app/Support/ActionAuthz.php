<?php

namespace App\Support;

/**
 * Filament actions that deliberately carry no `->authorize()`, and why.
 *
 * **The invariant this backs.** A write action must be gated in **both** `visible()` (the UI) and
 * `authorize()`/`abort_unless` (the actual gate). `visible()` alone is a UI decision, not an
 * authorization decision — the project treats it that way because a hidden action can still be
 * reachable by a crafted Livewire call, and because a reader cannot tell "this is safe" from
 * "someone forgot" without opening every closure.
 *
 * `ActionAuthzConformanceTest` scans every `Action::make(...)` chain under `app/Filament` and fails
 * on any chain that has an `->action(` but no gate and no entry here. That turns "remember to gate
 * the new action" — which is how this class of bug shipped in CAM and Sales — into a build failure.
 *
 * **Adding an entry is a decision, not a formality.** An exemption says *this action changes
 * nothing, or is gated somewhere the scanner cannot see*. If you are exempting a write because it
 * "feels fine", gate it instead: `->authorize()` costs one line.
 *
 * Keys are `<file basename>::<action name>`.
 */
class ActionAuthz
{
    /**
     * @var array<string, string> exempt action => the reason it needs no `->authorize()`
     */
    public const EXEMPT = [
        // Gated inside the method the action delegates to: Settings::save() opens with
        // `abort(403)` unless the user holds settings.manage. The scanner sees `->action('save')`
        // — a method reference — and cannot follow it.
        'Settings.php::save' => 'gated in Settings::save() itself (abort 403 unless settings.manage)',

        // Read-only renders of a record the user can already see. Reaching the action means
        // passing the resource's own view gate and its property scoping; the PDF adds no
        // authority. Module 29's close-out separately verified the PO PDF resolves through the
        // property-scoped query (no IDOR).
        'InvoicesTable.php::downloadPdf' => 'read-only render of an already-visible invoice',
        'InvoicesTable.php::downloadPdfBundle' => 'read-only render of already-visible invoices',
        'PurchaseRequestsTable.php::downloadPo' => 'read-only render of an already-visible purchase order',
        'EditPurchaseRequest.php::downloadPo' => 'read-only render of an already-visible purchase order',

        // Portal surface: the tenant downloading their OWN documents. The portal has no
        // permission model beyond `is_admin` for writes, and these records resolve through the
        // tenant-scoped query, so there is nothing to authorize against.
        'InvoicesTable.php::downloadPdf.portal' => 'tenant downloading their own invoice',
        'ListInvoices.php::downloadStatement' => 'tenant downloading their own statement',
        'ViewInvoice.php::downloadPdf' => 'tenant downloading their own invoice',

        // Writes nothing — the action body is a Notification toast. If this ever actually sends,
        // remove the exemption and gate it.
        'InvoicesTable.php::sendWhatsApp' => 'no write — shows a notification only',
    ];

    /** Is this action allowed to ship without an `->authorize()`? */
    public static function isExempt(string $file, string $action): bool
    {
        return isset(self::EXEMPT[basename($file).'::'.$action])
            || isset(self::EXEMPT[basename($file).'::'.$action.'.portal']);
    }
}
