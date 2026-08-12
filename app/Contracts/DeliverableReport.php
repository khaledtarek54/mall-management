<?php

namespace App\Contracts;

/**
 * A report that can be produced without a browser — so it can be emailed on a schedule.
 *
 * Every report page already turns its parameters into a CSV; the logic just lived inside the export
 * action's closure, where only a click could reach it. This contract is that same logic named, so
 * `reports:deliver` can run it from the scheduler.
 *
 * **Not every report implements it yet, and that is stated rather than hidden.**
 * `ReportCatalogue::NOT_DELIVERABLE` lists the ones that do not, with a reason, and a conformance
 * test fails on a report that is in neither camp. A scheduling UI that silently omitted half the
 * catalogue would look like the feature was broken; a list that says "this one cannot be scheduled
 * yet, because…" is information.
 */
interface DeliverableReport
{
    /**
     * This report, rendered from whatever parameters the page is currently carrying.
     *
     * @return array{filename: string, headers: array<int, string>, rows: array<int, array<int, string|int|float|null>>}
     */
    public function reportCsv(): array;
}
