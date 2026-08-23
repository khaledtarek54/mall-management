<?php

namespace App\Settings;

use App\Support\DocumentNumbering;
use Spatie\LaravelSettings\Settings;

/**
 * How the books are kept — the decisions that shape every period, not the money in them.
 */
class AccountingSettings extends Settings
{
    /**
     * The calendar month a fiscal year begins in. 1 = January.
     *
     * **This was hardcoded**, and `FiscalCalendar`'s own docblock admitted it: "a calendar year is
     * assumed (Jan–Dec); a fiscal year starting in another month is a future option." The reports
     * were already honest about it — they read `fiscal_years.starts_on` and only fall back to
     * 1 January when no row exists — so the data model always supported a July year and nothing
     * could create one.
     *
     * That is not a cosmetic gap. An entity on a July–June year would have every income statement,
     * every year-end close and every period-close gate running on somebody else's calendar, and the
     * only fix was a deploy. A fiscal year is a registration decision made once, by the accountant,
     * before the first entry is posted.
     *
     * **A fiscal year is named for the year it STARTS in.** `ensureYear(2026)` with a July start
     * means 1 July 2026 – 30 June 2027. That is the reading that leaves January-start installs
     * behaving exactly as before, which is what makes this safe to change under an existing
     * database — and the ambiguity is real enough to be worth stating rather than assuming.
     *
     * Changing it once periods carry posted entries is REFUSED, not warned about: it would re-date
     * periods that already have entries in them, so a document that was in an open period lands in
     * a closed one, or the reverse. See `App\Support\FiscalYearStart`.
     */
    public int $fiscal_year_start_month = 1;

    /**
     * Document number prefixes, keyed by document type. Empty = every type keeps its shipped letters.
     *
     * `INV-`, `CN-`, `JE-` and six more were literals inside nine models, so "our invoices are
     * numbered TX, not INV" was a deploy — and an operator arrives with conventions their auditor
     * already knows and their previous system printed for years.
     *
     * The window to set this closes at go-live: after the first invoice is sent the prefix is on
     * issued documents that cannot be renumbered. See `App\Support\DocumentNumbering`, which also
     * holds the rule that two document types may not share a prefix.
     *
     * @var array<string, string>
     */
    public array $document_prefixes = [];

    /**
     * When a document series starts counting again — `never` · `annual` · `monthly` (EG-10).
     *
     * Defaults to ANNUAL, which is what SAP, Oracle, NetSuite and Odoo do; Yardi and MRI never
     * reset. Atriom shipped MONTHLY, which no major system uses and nobody chose. See
     * {@see DocumentNumbering::RESET_SCHEMES}.
     *
     * Like the prefix, the window to set it closes at go-live: changing it starts a new series and
     * leaves the old documents on the old one.
     */
    public string $document_number_reset = DocumentNumbering::DEFAULT_RESET;

    /**
     * The term a NEW lease form starts from, in months.
     *
     * 36 was a literal on the form. It is a leasing convention rather than a law — an anchor mall
     * signs ten years and a kiosk signs one — and every operator's standard differs, so it was a
     * deploy to change a number that is only ever a starting point. The operator overwrites it on
     * any lease that differs; nothing derives from it after creation.
     */
    /**
     * How long an activity-log row is kept, in days. `0` keeps them for ever (EG-34).
     *
     * It was `config('activitylog.clean_after_days') = 365`, hardcoded, with no screen — and the
     * prune is SCHEDULED, monthly on the 1st, so it was really deleting audit history.
     *
     * 365 days is a web-application default, not an accounting one. Two things make it wrong here:
     *
     * - **Egyptian statutory book retention is longer.** Commercial and tax records are commonly
     *   cited as a FIVE-year obligation, so the audit trail was expiring years before the books it
     *   describes. The default is now 1,825 days.
     * - **Money records in this system are never deletable.** An invoice from four years ago is
     *   still on the books; losing the record of who voided a line on it leaves a document nobody
     *   can account for.
     *
     * **Bounded rather than infinite, on purpose.** The log names WHO did each thing, which is
     * personal data — PDPL asks that it not be kept longer than the purpose needs and that the
     * period be documented. A screen showing the chosen number IS that documentation; a hardcoded
     * constant never could be.
     */
    public int $activity_log_retention_days = 1825;

    public int $default_lease_term_months = 36;

    public static function group(): string
    {
        return 'accounting';
    }
}
