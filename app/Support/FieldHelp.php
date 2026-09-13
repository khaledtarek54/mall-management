<?php

namespace App\Support;

use App\Filament\Actions\ReversalReasonField;

/**
 * Where a piece of field guidance belongs, and how long it may be.
 *
 * **The problem was length, not placement.** `lang/en/admin.php` carried 125 `helpers.*` strings, of
 * which 100 ran over twelve words and 60 over twenty — several were 40–79-word paragraphs sitting
 * permanently under a single input. `LeaseForm` alone rendered 29 of them, which roughly doubled the
 * form's height and taught the operator to skim past all of it. Meanwhile the panel contained
 * exactly ONE `hintIcon`, and it passed no tooltip: the affordance that solves this had never been
 * used.
 *
 * The answer is not "tooltips instead of helper text". Hiding a constraint behind hover is worse
 * than showing it — it disappears on a touch screen, and a first-time operator cannot see the rule
 * that would have stopped them entering the wrong thing. So guidance is sorted by WHAT IT DOES:
 *
 *   `helperText`  always visible. It changes what you type or pick — a constraint, a derivation, a
 *                 consequence. Budget: {@see self::WORD_BUDGET} words, because past that it stops
 *                 being a line and becomes a block.
 *
 *   `hintIcon`    one hover or tap away, with a visible icon so it is discoverable. The "why" a
 *                 trained operator does not need on every visit. Content lives in `admin.hints.*`.
 *
 *   guide panel   what is really about the MODULE rather than the field. {@see ScreenGuides}.
 *
 * Nothing was cut in the move: every `admin.hints.*` string is the original helper text, verbatim,
 * which is also why the Arabic side needed no new translation for the tooltips — only the short
 * visible lines are new prose.
 */
class FieldHelp
{
    /**
     * How long an always-visible helper line may be.
     *
     * Eighteen rather than twelve: at a typical form column width twelve words is one line and
     * eighteen is two, and the cut-down had to stay a sentence rather than a fragment. Sixty-four
     * strings were over it; they were split, and none of the survivors is under five words either —
     * "Optional." is inside any budget and tells the operator nothing.
     */
    public const WORD_BUDGET = 18;

    /**
     * How long a reversal reason may be — {@see ReversalReasonField}.
     *
     * 500 characters is a sentence or two, which is what a reason is; the field existed at that cap
     * on the two actions that had one, and it lives here so the other eleven cannot pick their own.
     */
    public const REVERSAL_REASON_MAX_LENGTH = 500;

    /**
     * Long helper strings that stay long, and why.
     *
     * Every one of these was checked against its CALL SITE rather than its length. Two shapes earn
     * the exemption, and both were found by looking rather than by reasoning about the catalogue.
     *
     * @var array<string, string>
     */
    public const LONG_BY_DESIGN = [
        // Already displayed on hover or inside a dialogue — the very place the triage moves things
        // TO. Shortening these would lose information and buy no screen space at all.
        'statement_consistent' => 'Rendered as a column ->tooltip(), so it is already one hover away.',
        'match_line' => 'Rendered as a ->modalDescription(); a dialogue has room to explain itself.',
        'unmatch_line' => 'Its twin, on the same relation manager and for the same reason — a ->modalDescription() on the action that undoes what match_line did.',

        // Live feedback rather than explanation. These report the record's STATE — what is locked,
        // what was derived from which tariff, what looks mistyped — and a hint icon is the wrong
        // home for a message that is only shown when it applies.
        'posting_role_repoint_open' => 'A COUNT of what this change will restate, shown only when the account already carries postings (SW-134). Never a paragraph on a fresh install, and the number is the whole message.',
        'posting_role_repoint_closed' => 'Its closed-period twin, and the longer of the two because it has to say three separate things: how many lines re-derive, how many never can, and what the operator should do instead. Shortening it would drop the escape, and a warning with no way out is one people dismiss.',
        'billing_frequency_locked' => 'Shown only once the lease has been invoiced; the field\'s own hint icon carries the explanation.',
        'percentage_rent_threshold_annual' => 'The field already switches its LABEL and carries a warning ->hint(); a third affordance would be clutter.',
        'percentage_rent_threshold_annual_warning' => 'A conditional warning ->hint() — it appears only when the figure looks mistyped.',
        'cost_derived' => 'Live feedback with the tariff INTERPOLATED (:rate per :uom) — it reports where this figure came from, so there is nothing to move to a hint icon that would still be true on the next reading.',
        'cost_no_rate' => 'The other half of that live feedback: shown only when the meter has no tariff.',
    ];

    /**
     * Bounded number fields whose limit needs no sentence, and why.
     *
     * ## The gap the existing gate could not see
     *
     * `FieldHelpConformanceTest` polices the help that EXISTS — its length, its home, whether a hint
     * icon carries a tooltip. Nothing asked about the help that is MISSING, which is the same shape
     * as every "a gate can report on a set it has silently stopped collecting" note in CLAUDE.md.
     * Measured on 2026-08-24 by building all 66 create forms: **673 fields, 75 of them (11%) carry
     * any guidance at all; 258 are required and 235 of those explain nothing.**
     *
     * "Every required field needs help" is the wrong bar and would produce 235 filler sentences —
     * exactly what {@see WORD_BUDGET} exists to prevent. A field called Name needs no explanation.
     *
     * ## The bar that IS right
     *
     * **A number the form will REFUSE, where the operator cannot infer the limit.** That is the
     * failure this catalogue's own rule names: "a first-time operator cannot see the rule that would
     * have stopped them". A lease term capped at 120 months is a policy nobody can guess; a
     * percentage capped at 100 is arithmetic. So the gate requires help on any field carrying a
     * `maxValue()` or a `minValue()` past zero — unless it is registered here with the reason its
     * bound explains itself.
     *
     * Registered by `{resource-directory}.{field}` — the directory under `app/Filament/Admin/
     * Resources`, which is what a SOURCE sweep can see. Not the resource class basename: keying it
     * that way meant `FixedAssets` had to be guessed back into `FixedAssetResource`, and three
     * entries silently matched nothing while looking correct.
     *
     * @var array<string, string>
     */
    public const SELF_EVIDENT_BOUNDS = [
        // A percentage bounded 0–100 is arithmetic, not policy.
        'Invoices.vat_rate' => 'A percentage bounded 0–100 states its own limit; the rate itself is picked from the tax catalogue.',
        'CreditNotes.vat_rate' => 'A percentage bounded 0–100 states its own limit; the rate itself is picked from the tax catalogue.',

        // A display-order integer. The ceiling is a typo guard, not a rule about the business, and
        // nothing an operator does depends on knowing whether it is 999 or 9999.
        'TaxCodes.sort_order' => 'A display-order number; the ceiling is a typo guard, not a rule anyone works to.',

        // "At least one" on a count of something. An asset that lasts zero months and a schedule
        // that repeats zero times are not values anyone means to enter.
        'FixedAssets.useful_life_months' => 'A duration must be at least one month; zero is not a value anyone means to enter.',
        'ServicePlans.frequency_value' => 'A repeat count must be at least one; zero is not a value anyone means to enter.',

        // A money ceiling set at the DATABASE COLUMN's width. It is a typo guard against an
        // out-of-range 500, not a rule about supplier invoices — no bill a mall receives comes
        // within four orders of magnitude of it — so a sentence under the field would be telling
        // the operator about a limit they can never meet.
        'VendorBills.subtotal' => 'The ceiling is the decimal(14,2) column, not a rule about bills; no real invoice approaches it.',
        'VendorBills.vat_amount' => 'The ceiling is the decimal(14,2) column, not a rule about tax; no real invoice approaches it.',
    ];

    /** Does this field's bound explain itself without a sentence? */
    public static function boundIsSelfEvident(string $resourceDirectory, string $field): bool
    {
        return isset(self::SELF_EVIDENT_BOUNDS[$resourceDirectory.'.'.$field]);
    }

    /**
     * Section descriptions are not field help.
     *
     * A `Section->description()` appears once above a group of fields rather than under each one, so
     * it costs a fraction of the vertical space and is the right place for context. Keyed by
     * suffix because that is how the catalogue names them.
     */
    public static function isSectionDescription(string $key): bool
    {
        return str_ends_with($key, '_section');
    }

    public static function isExempt(string $key): bool
    {
        return isset(self::LONG_BY_DESIGN[$key]);
    }

    /**
     * The helpers over budget on the day the sweep became DERIVED (SW-260, 2026-09-13) — a debt
     * ledger, not an exemption list.
     *
     * `FieldHelpConformanceTest` had measured two catalogues by NAME (`admin.helpers.*` and
     * `admin.actions.*_helper`), and a helper rendered from any other group was measured by
     * nothing: 480 `->helperText(__('…'))` call sites under `app/`, 143 of them in those two
     * groups. That is how a 24-word helper under a lease option's status passed on 2026-09-13, and
     * how the 74 below — the settings screen's paragraphs most of all — had never been asked. The
     * gate now derives its population from the call sites, so a helper is measured by being
     * RENDERED, whatever group its words live in.
     *
     * Every key here is a wording edit owed — keep the line that changes what the operator types,
     * move the WHY behind a `hintIcon()` — in both languages. The gate holds it as a RATCHET: an
     * unlisted helper over budget fails (new work meets the budget from today), and a listed one
     * that has come under budget fails too (a paid debt leaves the ledger). Nothing here carries a
     * reason, because "it was already long" is not one; a helper that is long BY DESIGN belongs in
     * {@see LONG_BY_DESIGN} with its reason.
     *
     * @var list<string>
     */
    public const OVER_BUDGET_BACKLOG = [
        'admin.settings.fields.mail_enabled_helper',
        'admin.settings.fields.wht_default_tax_code_helper',
        'admin.settings.fields.straight_line_rent_enabled_help',
        'admin.settings.fields.paymob_enabled_helper',
        'admin.cam.estimate_charge_codes_help',
        'admin.settings.fields.new_charges_follow_escalation_helper',
        'admin.settings.fields.holdover_default_rate_pct_helper',
        'admin.settings.fields.ar_aging_bucket_days_helper',
        'admin.cam.gross_up_pct_help',
        'admin.facility.helpers.criticality',
        'admin.facility.days_of_week_hint',
        'admin.settings.fields.default_payment_terms_days_helper',
        'admin.settings.fields.levy_rate_percent_helper',
        'admin.charge_schedule.vat_override_hint',
        'admin.report_hub.recipients_help',
        'admin.facility.area_hint',
        'admin.fields.is_publicly_listed_helper',
        'admin.saved_views.share_help',
        'admin.settings.fields.auto_apply_tenant_credit_helper',
        'admin.actions.change_rent_effective_from_hint',
        'admin.settings.fields.seller_trn_helper',
        'admin.report_hub.share_view_help',
        'admin.fields.brand_logo_helper',
        'admin.charge_schedule.add_effective_hint',
        'admin.settings.fields.wht_enabled_helper',
        'vendor.jobs.quote_supplementary_helper',
        'admin.charge_schedule.end_from_hint',
        'admin.settings.fields.lease_activation_requires_helper',
        'admin.settings.fields.reservation_valid_days_helper',
        'admin.reports.include_zero_balances_help',
        'admin.actions.premises_effective_from_hint',
        'admin.fields.sales_report_help',
        'admin.vendors.wht.code_hint',
        'admin.facility.penalty.basis_hint',
        'admin.cam.denominator_basis_help',
        'admin.fixed_asset_categories_screen.help.default_salvage',
        'admin.facility.trigger_type_hint',
        'admin.post_dated_cheques.fields.lease_hint',
        'admin.facility.help.supplementary',
        'admin.settings.fields.document_number_reset_help',
        'admin.settings.fields.nsf_fee_amount_helper',
        'admin.sales_analytics.as_of_help',
        'admin.procurement.tier_hint',
        'admin.vendors.wht.gross_hint',
        'admin.cam.variable_pct_help',
        'admin.fields.store_logo_hint',
        'admin.lease_options.notice_given_at_hint',
        'admin.unit_ownerships.charges.from_hint',
        'admin.charge_schedule.add_type_hint',
        'admin.rent_roll.as_of_help',
        'admin.report_hub.day_of_month_help',
        'admin.vendors.wht.exempt_hint',
        'admin.facility.penalty.rate_hint',
        'admin.tenants.documents.coverage_amount_hint',
        'admin.tenants.documents.expires_on_hint',
        'admin.fixed_asset_categories_screen.help.tag_prefix',
        'admin.actions.holdover_rate_hint',
        'admin.settings.fields.late_fee_maximum_helper',
        'admin.settings.fields.default_security_deposit_basis_helper',
        'admin.tenant_request_subcategories.help.trade',
        'admin.recurring_expenses.help.description',
        'admin.facility.fault.notes_hint',
        'admin.fields.owner_helper',
        'admin.settings.fields.activity_log_retention_days_help',
        'admin.settings.fields.monthly_billing_day_helper',
        'admin.occupancy_cost.window_help',
        'admin.opening_balances.helpers.trial_balance',
        'admin.work_permits.help.conditions',
        'admin.facility.equipment.code_hint',
        'admin.lease_cam_terms.help.cap_type',
        'admin.fields.ownership_percentage_helper',
        'admin.fields.owned_until_helper',
        'admin.facility.help.route_stop',
        'admin.settings.fields.fiscal_year_start_month_help',
    ];

    public static function isKnownOverBudget(string $key): bool
    {
        return in_array($key, self::OVER_BUDGET_BACKLOG, true);
    }
}
