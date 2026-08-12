<?php

namespace App\Services\OwnerAccounting;

use App\Models\Asset;
use App\Models\User;
use App\Support\OwnerPack;
use App\Support\ReportXlsx;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use ZipArchive;

/**
 * One owner's month-end pack, as a single file (RP-08).
 *
 * Module 32 issues the owner STATEMENT — what Jawad is owed. This is the evidence behind it: how
 * each of his malls traded, who is in them, and who has not paid. Today an operator opens five
 * reports, sets the property on each, exports each, and attaches five files to an email — per owner,
 * per month, and every step is a chance to attach the wrong property's file.
 *
 * ## The guard is the OWNER LOGIN, and it was worth finding out which
 *
 * A portfolio-wide render leaks: an income statement covering every mall, sent to one owner, shows
 * him another landlord's revenue — and it looks exactly like a working feature, because the file
 * opens and the numbers are real. An operator would have to know a rival landlord's revenue by
 * heart to notice.
 *
 * **What actually stops that is `Auth::login($owner)`.** These reports scope through `TenantScope`,
 * which derives what may be seen from the AUTHENTICATED USER's properties — so rendering as the
 * owner makes the scope match the recipient by construction. Deleting that line puts another
 * landlord's tenants into the pack, which is exactly what mutating it proved.
 *
 * Deleting the per-asset `setTenant()` below, by contrast, changes nothing about what leaks. It is
 * there for the SECOND reason the pack is built per property: an owner with three malls wants three
 * sets of figures rather than one consolidation, and the folders come from that loop. Documented
 * this way round on purpose — a comment that credits the wrong guard is how the real one gets
 * deleted by somebody tidying up.
 *
 * The login is restored in `finally`, so a throw mid-pack cannot leave the request authenticated as
 * somebody else — the operator would spend the rest of it seeing one property and writing as a
 * landlord.
 */
class BuildOwnerPackService
{
    /**
     * Build the pack and return the path to the zip.
     *
     * @param  User  $owner  the owner the pack is FOR — the reports are rendered as them
     */
    public function build(User $owner, CarbonImmutable $periodStart, CarbonImmutable $periodEnd): string
    {
        // Tenure, not "ever owned". A former owner's pack must stop at the properties they still
        // hold on the period end — otherwise a landlord who sold in March still receives April's
        // trading figures for a building that is not theirs.
        $assets = $owner->currentOwnedAssets($periodEnd->toDateString())->get();

        if ($assets->isEmpty()) {
            throw new RuntimeException(__('admin.owner_pack.no_properties'));
        }

        $path = storage_path('app/owner-packs/'.$this->packName($owner, $periodEnd).'.zip');

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException(__('admin.owner_pack.cannot_write'));
        }

        $previousUser = Auth::user();
        $previousTenant = Filament::getTenant();

        try {
            Auth::login($owner);

            foreach ($assets as $asset) {
                $this->addAssetReports($zip, $asset, $periodStart, $periodEnd);
            }
        } finally {
            // Restored whatever happened. A throw halfway through must not leave the request
            // authenticated as the owner or pointed at their property.
            Filament::setTenant($previousTenant, isQuiet: true);

            if ($previousUser instanceof User) {
                Auth::login($previousUser);
            } else {
                Auth::logout();
            }

            $zip->close();
        }

        return $path;
    }

    /** Every report in the pack, for one property, as its own worksheet file. */
    private function addAssetReports(ZipArchive $zip, Asset $asset, CarbonImmutable $from, CarbonImmutable $to): void
    {
        Filament::setTenant($asset, isQuiet: true);

        foreach (OwnerPack::reports() as $reportClass) {
            $page = new $reportClass;

            $this->applyPeriod($page, $from, $to);

            // A report the owner may not open is skipped rather than rendered empty. An empty
            // worksheet in a pack reads as "nothing happened this month", which is a different
            // claim from "you were not sent this".
            if (! $reportClass::canAccess()) {
                continue;
            }

            $report = $page->reportCsv();

            $zip->addFromString(
                sprintf('%s/%s.xlsx', $this->folder($asset), $report['filename']),
                ReportXlsx::toString($report['headers'], $report['rows']),
            );
        }
    }

    /**
     * Point a report at the pack's period, in whichever vocabulary it speaks.
     *
     * The reports genuinely differ — a statement is asked FOR a period and an ageing report is asked
     * AS OF a date — which is why `ReportFilters` is a vocabulary rather than one bar (RP-02). The
     * pack has to speak both, and setting only one would silently hand the owner a rent roll dated
     * today inside a pack labelled March.
     */
    private function applyPeriod(object $page, CarbonImmutable $from, CarbonImmutable $to): void
    {
        if (property_exists($page, 'year')) {
            $page->year = (int) $to->year;
        }

        if (property_exists($page, 'period')) {
            $page->period = $to->format('Y-m');
        }

        if (property_exists($page, 'asOf')) {
            $page->asOf = $to->toDateString();
        }

        if (property_exists($page, 'from')) {
            $page->from = $from->toDateString();
        }

        if (property_exists($page, 'to')) {
            $page->to = $to->toDateString();
        }
    }

    /** One folder per property, so an owner with three malls can tell them apart at a glance. */
    private function folder(Asset $asset): string
    {
        return str($asset->code ?: $asset->name)->slug()->value();
    }

    private function packName(User $owner, CarbonImmutable $periodEnd): string
    {
        return sprintf('owner-pack-%s-%s', str($owner->name)->slug()->value(), $periodEnd->format('Y-m'));
    }
}
