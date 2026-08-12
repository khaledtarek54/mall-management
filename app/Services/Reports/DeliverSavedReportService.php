<?php

namespace App\Services\Reports;

use App\Contracts\DeliverableReport;
use App\Mail\SavedReportDelivered;
use App\Models\SavedReport;
use App\Support\ReportCatalogue;
use App\Support\ReportCsv;
use App\Support\ReportParameters;
use Illuminate\Support\Facades\Auth;
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

        // Authenticate as the owner for the render only, and put the guard back afterwards however
        // it goes. Leaking an authenticated user out of this method would hand the NEXT saved
        // report in the run somebody else's scope.
        $previous = Auth::user();
        Auth::setUser($owner);

        try {
            if (! rescue(fn () => $page::canAccess(), false, false)) {
                return false;
            }

            $instance = app($page);
            $instance->mount();
            ReportParameters::apply($instance, $saved->parameters ?? []);

            $csv = $instance->reportCsv();
        } finally {
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
