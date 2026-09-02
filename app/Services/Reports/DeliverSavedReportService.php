<?php

namespace App\Services\Reports;

use App\Contracts\DeliverableReport;
use App\Mail\SavedReportDelivered;
use App\Models\Asset;
use App\Models\SavedReport;
use App\Support\ReportCatalogue;
use App\Support\ReportCsv;
use App\Support\ReportParameters;
use Filament\Facades\Filament;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Renders a saved report and emails it — the one place scheduled delivery actually happens.
 *
 * ## It runs AS the person who saved it
 *
 * This is the part that matters. A report reads whatever the current user is allowed to read: the
 * ledger scopes to visible properties, the resources scope to assigned assets. On a console command
 * there is no current user, so a report rendered as nobody either sees nothing or — worse — sees
 * everything. So the saved view's OWNER is authenticated for the duration of the render, and their
 * own `canAccess()` is checked first.
 *
 * That also gives the right answer when somebody's access is withdrawn: the delivery stops, because
 * the report they saved is one they can no longer open. A schedule is not a standing grant.
 *
 * ## Failures are per-report
 *
 * One saved view that cannot render must not stop the rest of the run — a month-end morning is
 * exactly when the other five matter. The caller catches and reports; nothing here retries, because
 * the schedule will come round again and a report delivered twice is worse than one delivered late.
 */
class DeliverSavedReportService
{
    /**
     * Render and send one saved view. Returns false when it could not be delivered.
     */
    public function deliver(SavedReport $saved): bool
    {
        $page = ReportCatalogue::pageFor($saved->report);

        if ($page === null || ! is_a($page, DeliverableReport::class, true)) {
            return false;
        }

        $owner = $saved->user;

        if ($owner === null || blank($saved->recipients)) {
            return false;
        }

        $csv = null;

        // The PROPERTY the view was saved in, re-established for the render.
        //
        // Most report pages carry no `$assetId` — they scope with `TenantScope::currentAssetId()`,
        // which reads the Filament tenant. There is no tenant in a queue worker, so that answered
        // null, and null means *no property filter*: a rent roll saved in one mall was delivered
        // every month as the whole portfolio — tenant names, contracted rents, per-sqm rates and
        // deposits — to the addresses the schedule names. Those are routinely outside the business;
        // the recipients field invites the owner's accountant and the auditor precisely because they
        // have no login here, which also means no way to tell whose tenants they are reading.
        //
        // Refused rather than guessed when the view recorded no property. A view saved before this
        // was captured is indistinguishable from one deliberately spanning the portfolio, and only
        // one of those two is safe to send.
        $assetId = ReportParameters::propertyOf($saved->parameters);

        if ($assetId === null) {
            // Logged, not silent. A schedule that stops arriving is the failure nobody reports —
            // the recipient assumes it is coming and the operator assumes it went. A view saved
            // before the property was captured needs re-saving once, and this line is what tells
            // somebody that.
            Log::warning('Saved report skipped: it records no property, so it cannot be scoped for delivery.', [
                'saved_report_id' => $saved->id,
                'report' => $saved->report,
                'name' => $saved->name,
            ]);

            return false;
        }

        $asset = Asset::find($assetId);

        // Access is re-checked at DELIVERY, not trusted from the day it was saved — the same
        // reasoning as running the render as the owner. Someone moved off a mall stops receiving
        // that mall's reports.
        if ($asset === null || ! $owner->canAccessTenant($asset)) {
            return false;
        }

        // Authenticate as the owner for the render only, and put the guard back afterwards however
        // it goes. Leaking an authenticated user out of this method would hand the NEXT saved
        // report in the run somebody else's scope.
        $previous = Auth::user();
        $previousTenant = Filament::getTenant();
        Auth::setUser($owner);

        try {
            Filament::setTenant($asset, isQuiet: true);

            if (! rescue(fn () => $page::canAccess(), false, false)) {
                return false;
            }

            $instance = app($page);
            $instance->mount();

            // **THE PERIOD FOLLOWS THE SCHEDULE, NOT THE DAY THE VIEW WAS SAVED.** `mount()` has
            // just derived this report's period from `now()`; re-applying the snapshot on top of it
            // put the frozen one back, so "send every month" emailed September's figures in October,
            // November and for ever. Nothing errors and the CSV arrives on time — the only tell is
            // that the numbers never move, which is the failure a recipient notices last if at all.
            //
            // DROPPED rather than rewritten: `apply()` skips a key it is not given, so the page keeps
            // the default its own `mount()` produced. One definition of what "this month" means, on
            // the page that owns the question.
            //
            // Every other saved parameter is kept — the ageing bucket, the ledger account, the
            // comparison basis — because those are the operator's SHAPE rather than their moment.
            // And the browser is untouched: opening a saved view still re-applies its period exactly
            // as saved, because a link is a moment and a schedule is a cadence.
            ReportParameters::apply(
                $instance,
                Arr::except($saved->parameters ?? [], ReportCatalogue::reportingPeriodOf($page)),
            );

            $csv = $instance->reportCsv();
        } finally {
            // Both are put back however it goes. Leaking either out of this method would hand the
            // NEXT saved report in the run somebody else's scope — the tenant every bit as much as
            // the user, now that the tenant is what most of these reports scope by.
            Filament::setTenant($previousTenant, isQuiet: true);
            $previous ? Auth::setUser($previous) : Auth::forgetUser();
        }

        if ($csv === null) {
            return false;
        }

        Mail::to($saved->recipients)->send(new SavedReportDelivered(
            name: $saved->name,
            filename: ReportCsv::filename($csv['filename']),
            csv: ReportCsv::toString($csv['headers'], $csv['rows']),
            rowCount: count($csv['rows']),
        ));

        return true;
    }
}
