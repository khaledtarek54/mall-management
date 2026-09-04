<?php

namespace App\Support;

/**
 * **What a work order's STATUS and PRIORITY read as, and what colour they wear — in one place.**
 *
 * Measured 2026-09-03 by sweeping every `TextColumn::make(…)->badge()` in `app/Filament` whose
 * column is one `App\Support\ValueSets` governs. 150 of them; four carried no formatter at all, and
 * Filament renders a state RAW unless one is given (`CanFormatState::formatState()` evaluates
 * `$this->formatStateUsing ?? $state`, so the stored string is what reaches the page):
 *
 *   - `Vendor/Resources/WorkOrders/Pages/ListWorkOrders` → `status` and `priority`. **The
 *     contractor's only screen**, so an external maintenance company read `in_progress` and
 *     `urgent` — the database codes — in English on the English panel and in English on the Arabic
 *     one (SW-069).
 *   - `BankAccounts/Tables/BankAccountsTable` → `currency`, deliberately verbatim: an ISO 4217 code
 *     is the same three letters in both languages and is what the operator reconciles against a
 *     bank statement ({@see ActivityVocabulary::verbatimReason()} records that reason already).
 *   - `BillingForecastRelationManager` → a SYNTHETIC `status` built as prose by its own array
 *     builder, on a table fed from `->records()` rather than from a column.
 *
 * The other three work-order badges — the operator's board (`FacilityWorkOrdersTable`, twice) and
 * `SlaPoliciesTable` — each spelled the same pair out for themselves: a `formatStateUsing` over
 * `admin.facility.{statuses,priorities}` beside its own `match()` on colour. Three copies of one
 * statement, and a fourth screen with none.
 *
 * **The colour travels with the word, deliberately.** They are one statement about a code, and the
 * dispatcher's board and the contractor's list must not disagree about which job looks urgent.
 *
 * **The MAP is deliberately NOT here.** Every picker and filter in the panel offers these codes as
 * `->options(fn () => __('admin.facility.priorities'))` — a lang array read directly, which is this
 * codebase's idiom — and a wrapper would only be a second name for one array. What was re-spelled,
 * and what this owns, is the code → (word, colour) pair.
 *
 * **{@see Translate::orHumanized()}, never a bare `__()`.** A missing key returns the KEY, so a
 * status added to `FacilityWorkOrder::STATUSES` before somebody writes its Arabic would print
 * `admin.facility.statuses.on_hold` onto a third party's screen — which is worse than the raw code,
 * not better. A blank state renders as nothing rather than as a humanised empty string.
 */
final class FacilityVocabulary
{
    /** The operator's word for a work-order status, in the reader's language. */
    public static function statusLabel(?string $status): string
    {
        return blank($status)
            ? ''
            : Translate::orHumanized("admin.facility.statuses.{$status}", $status);
    }

    /**
     * The badge colour for a work-order status.
     *
     * Verbatim the `match()` `FacilityWorkOrdersTable` has carried since the board was written —
     * finished is green, running is amber, cancelled is grey, and anything else (today only `open`)
     * is neutral information rather than an alarm.
     */
    public static function statusColor(?string $status): string
    {
        return match ($status) {
            'done' => 'success',
            'in_progress' => 'warning',
            'cancelled' => 'gray',
            default => 'info',
        };
    }

    /** The operator's word for a work-order priority, in the reader's language. */
    public static function priorityLabel(?string $priority): string
    {
        return blank($priority)
            ? ''
            : Translate::orHumanized("admin.facility.priorities.{$priority}", $priority);
    }

    /** The badge colour for a work-order priority — the same one the operator's board uses. */
    public static function priorityColor(?string $priority): string
    {
        return match ($priority) {
            'urgent' => 'danger',
            'high' => 'warning',
            'low' => 'gray',
            default => 'info',
        };
    }
}
