<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Support\LogOptions;

/**
 * What the audit trail records, and the short list of what it deliberately does not.
 *
 * ## Why this exists: the allowlist was inverted
 *
 * Every audited model used to name the columns it wanted logged — `->logOnly([...])` — which meant
 * a column was invisible to the audit trail until somebody remembered it. Measured 2026-08-24
 * across 84 audited models: **1,063 operator-settable columns, 598 audited, 467 invisible (43%)**,
 * and **33 models where editing `notes` recorded nothing at all** — not a row with an empty diff,
 * no row, because `dontLogEmptyChanges()` suppresses a save in which nothing *watched* moved.
 * `Lease` audited 9 of its 52 fillable columns. That is how it was found: an operator changed the
 * notes on a lease and the activity log stayed empty.
 *
 * Yardi, MRI and Entrata all audit the ENTITY and exclude noise — you switch auditing on for a
 * table and it captures the row diff minus system and derived fields. This inverts Atriom to match:
 * **everything an operator can set is audited unless it is on the list below.** The pressure that
 * produced the short allowlists was real — the vocabulary gate requires an EN+AR label for every
 * logged column, so a nine-column list was the cheap path — but the answer to that is labels, not
 * an audit trail with holes in it.
 *
 * ## Reading the list
 *
 * An entry has to say why the trail is better off without it. Three things earn a place and
 * nothing else does: a value **no person set** (a scheduled scan's stamp), a value **derived from
 * one already audited** (so the row would record a consequence twice and bury the act), and a
 * value that **must never be written down** (a credential). Anything else — including a column
 * that looks like plumbing — is audited, because the cost of an unnecessary row is noise and the
 * cost of a missing one is an operator who cannot answer what happened.
 */
final class ActivityLogging
{
    /**
     * Columns never audited on any model, each with the reason it earns the exclusion.
     *
     * @var array<string, string>
     */
    public const NEVER = [
        // ── Credentials. Non-negotiable: these must not exist in a readable table. `password` is
        // FILLABLE on both User and Tenant, so flipping to logFillable() without this would have
        // started writing password hashes into activity_log on the first save.
        'password' => 'A credential. It must never be written to a readable audit table, and a hash is still a credential.',
        'remember_token' => 'A session credential — churn is not an audit event and the value is a live secret.',
        'two_factor_secret' => 'A live TOTP seed. Logging it would let anyone with log access mint codes.',
        'two_factor_recovery_codes' => 'Single-use bypass codes — logging them defeats the second factor entirely.',
        'two_factor_confirmed_at' => 'Moves as a side effect of the 2FA enrolment flow, which is audited as its own act.',
        'api_token' => 'A live bearer credential.',

        // ── Written by a schedule, not by a person. The trail answers "who did this"; a scan stamp
        // has no who, and one row per nightly sweep per record would bury every human act.
        'last_generated_at' => 'Stamped by the recurring-document generator, not typed by anyone.',
        'last_generated_on' => 'Stamped by the recurring-document generator, not typed by anyone.',

        // ── Derived from a column that IS audited. Recording both writes the consequence beside
        // the act and doubles every diff; the service that recomputes them is the thing to follow.
        'paid_amount' => 'Derived by Invoice::recomputeTotals() from the four settlement channels — the settlement is the act, this is its consequence.',
        'balance' => 'Derived by Invoice::recomputeTotals() as total − paid_amount; never set directly by anything.',
        'credit_applied_amount' => 'Derived by the credit-note application, which is itself audited.',
        'paid_to_date' => 'A running total the payment path recomputes; each payment is already its own audited record.',
        'landlord_unrecovered_amount' => 'Derived by the CAM generator as actual − Σ allocated.',
        'denominator_used_sqm' => 'Resolved by the CAM apportionment from the basis, which is audited.',
        'grossed_up_expense' => 'Computed from the pool total and gross-up percentage, both audited.',
        'net_operating_income' => 'A statement figure computed from revenue and expense, both audited.',
        'total_expense' => 'A statement roll-up of audited components.',
        'total_revenue' => 'A statement roll-up of audited components.',
        'income_breakdown' => 'A rendered JSON summary of figures audited individually.',
        'search_text' => 'The folded search blob — a pure function of the row\'s own audited attributes, rewritten on every save.',
        'slug' => 'Derived from the name, which is audited.',

        // ── Machine payloads. A provider\'s raw response is evidence on its own record, not a diff
        // a person reads; rendering one in a Changes cell produces a wall of JSON.
        'gateway_response' => 'The payment provider\'s raw response body — kept on the record itself, unreadable as a diff.',
        'description_key' => 'JournalNarrative\'s lookup key; its resolved prose sibling is what a person reads.',
        'description_data' => 'JournalNarrative\'s placeholder payload, meaningless without the key.',
        'custom_fields' => 'The VIRTUAL write attribute for HasCustomFields — the stored `metadata` column is the audited one, and logging both records every answer twice.',

        // ── The frozen module. ETA (module 16) is frozen in code and removed from every operator
        // surface; an audit column would put it back on the one screen the freeze did not clear.
        'eta_status' => 'Module 16 is FROZEN (Modules::FROZEN) and deliberately invisible on every operator surface.',
        'eta_submission_id' => 'Module 16 is FROZEN — see Modules::FROZEN.',
        'eta_long_id' => 'Module 16 is FROZEN — see Modules::FROZEN.',
        'eta_submitted_at' => 'Module 16 is FROZEN — see Modules::FROZEN.',
        'eta_response' => 'Module 16 is FROZEN — see Modules::FROZEN.',
    ];

    /**
     * Column SUFFIXES never audited, so the family inherits the decision.
     *
     * A suffix rather than a list because these arrive one per feature and always mean the same
     * thing — the alternative is a register that is one column behind the code for ever.
     *
     * @var array<string, string>
     */
    public const NEVER_SUFFIX = [
        '_notified_at' => 'A scheduled scan stamped the moment it sent an alert. There is no causer, and one row per sweep per record would bury every human act in the trail.',
    ];

    /**
     * The polymorphic `*_type` halves — excluded only when a sibling `*_id` column exists.
     *
     * A plain `_type` SUFFIX rule is what a first draft used and it was wrong within minutes:
     * it swallowed `escalation_type` and `percentage_rent_calculation_type` on Lease alone, which
     * are operator classifications and among the most audit-worthy columns on the record. A morph
     * half is identifiable by its PAIR, not by its name — `noteable_type` is structural because
     * `noteable_id` sits beside it, and that test stays right as new ones arrive.
     */
    public const MORPH_TYPE_REASON = 'The structural half of a polymorphic pair (a sibling `*_id` exists) — it stores a morph alias, renders as a class name, and says nothing the audited id beside it does not.';

    /**
     * The audit options every model shares.
     *
     * @param  Model  $model  the record itself, so suffix rules resolve against its real columns
     * @param  string  $logName  the log this model files under — the one thing that stays per-model
     * @param  list<string>  $alsoLog  columns to audit that are NOT fillable (a service-set column
     *                                 that still records a decision, e.g. FacilityWorkOrder::sla_clock)
     * @param  array<string, string>  $alsoExcept  model-specific exclusions, column => reason
     */
    public static function for(Model $model, string $logName, array $alsoLog = [], array $alsoExcept = []): LogOptions
    {
        $excluded = self::excludedFor($model, $alsoExcept);

        return LogOptions::defaults()
            // Everything an operator can set. `logFillable()` and `logOnly()` UNION rather than
            // override (spatie merges fillable ∪ unguarded ∪ explicit, then subtracts excluded),
            // which is what lets $alsoLog add a non-fillable column back.
            ->logFillable()
            ->logOnly($alsoLog)
            ->logExcept($excluded)
            ->logOnlyDirty()
            // Without this a save touching ONLY excluded columns still writes a row — spatie
            // decides whether to log by diffing getDirty() against this list, not against the
            // logged set, so a password-only save produced a row with an empty diff. `updated_at`
            // belongs here because it is dirty on every save.
            ->dontLogIfAttributesChangedOnly([...$excluded, 'updated_at'])
            ->dontLogEmptyChanges()
            ->useLogName($logName);
    }

    /**
     * The exclusions that actually apply to one model — the shared list narrowed to its columns.
     *
     * Narrowed rather than passed whole so that `logExcept()` names only real columns, which keeps
     * the conformance gate able to tell a live exclusion from a stale one.
     *
     * @param  array<string, string>  $alsoExcept
     * @return list<string>
     */
    public static function excludedFor(Model $model, array $alsoExcept = []): array
    {
        $columns = [...$model->getFillable(), ...array_keys($alsoExcept)];

        $excluded = array_filter(
            $columns,
            fn (string $column): bool => array_key_exists($column, self::NEVER)
                || array_key_exists($column, $alsoExcept)
                || self::matchesNeverSuffix($column)
                || self::isMorphTypeHalf($column, $columns),
        );

        return array_values(array_unique($excluded));
    }

    /** Whether a column falls under one of the {@see NEVER_SUFFIX} families. */
    public static function matchesNeverSuffix(string $column): bool
    {
        foreach (array_keys(self::NEVER_SUFFIX) as $suffix) {
            if (str_ends_with($column, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A `*_type` column that is the structural half of a morph pair — see {@see MORPH_TYPE_REASON}.
     *
     * @param  list<string>  $columns  the model's own columns, which is what makes the pair visible
     */
    public static function isMorphTypeHalf(string $column, array $columns): bool
    {
        if (! str_ends_with($column, '_type')) {
            return false;
        }

        return in_array(substr($column, 0, -5).'_id', $columns, true);
    }
}
