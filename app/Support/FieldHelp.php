<?php

namespace App\Support;

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
}
