<?php

namespace App\Support;

use App\Support\Attributes\DeletableWhenUnused;
use App\Support\Attributes\DeletionAllowed;
use App\Support\Attributes\NeverDeletable;
use App\Support\Filament\AnnouncingDeleteAction;
use App\Support\Filament\ResourceAbility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use ReflectionClass;

/**
 * What may be deleted, and what must be corrected instead.
 *
 * **The decision (operator, 2026-07-31).** A financial document is not a row you remove — it is a
 * record of something that happened. Under Egyptian bookkeeping (and ETA e-invoicing once live) an
 * issued invoice is a legal record: you cancel it or credit-note it, and the trail survives. This
 * system already speaks that language — cancel, void, credit note, reverse, un-apply — and every
 * one of those leaves a document explaining the change. Delete was a fifth path that bypassed all
 * of them.
 *
 * **What it looked like before.** `Invoice`, `Payment` and `JournalEntry` had no deletion guard at
 * all (only `CreditNote` and `MeterReading` did), and every one of the eight money resources
 * offered a Delete button on its Edit page. Soft deletes meant nothing was destroyed — but a
 * super_admin could delete a PAID invoice and the tenant's AR would simply change, with no
 * cancellation, no reason, and nothing to show an auditor. The money moved and the paperwork
 * didn't.
 *
 * **The tiers**, each now declared as an attribute ON the model it describes:
 *
 * - {@see NeverDeletable} — money and audit records. No Delete button, no `.delete` permission, and
 *   a model-level guard as the backstop. Correct via the document's own correction path.
 * - {@see DeletableWhenUnused} — master data that history can point at. Deletable only while nothing
 *   references it; once it has been used, deletion is REFUSED and the operator is pointed at
 *   deactivation. Operator's call 2026-07-31, matching Yardi/MRI/Entrata: none of them offer
 *   "delete anyway with a warning", because the damage lands on the reports and audit trail that
 *   referenced the record, not on the record itself.
 * - {@see DeletionAllowed} — everything else keeps the standard gate: super_admin only,
 *   soft-deleted, bulk-delete off. The attribute still has to say WHY.
 *
 * **Why the tiers moved out of this file (2026-08-15).** They were three hand-maintained arrays
 * listing every model alphabetically, and they were one of ~20 such registers: adding a model meant
 * editing seven to nine of them, each far from the model itself. The classification now lives on the
 * class it classifies, and this file derives the registers instead of storing them.
 *
 * **That does not weaken the gate, because the gate never trusted this file for completeness.**
 * `DeletionPolicyConformanceTest` discovers models by globbing `app/Models` and then asks whether
 * each one is classified — so a model declaring no attribute is still unclassified and still fails
 * the build. A register derived from the same source it is checked against could not catch an
 * omission; this one is checked against the filesystem, which is the thing that actually grows.
 *
 * `DeletionPolicyConformanceTest` also fails the build when a NEVER model's resource ships a Delete
 * action or its permission reappears — the hand-maintained delete test it replaces covered 10 of
 * 41 resources, which is how a gap that size stayed invisible.
 */
class DeletionPolicy
{
    /**
     * The derived registers, built once per process.
     *
     * Only the ENUMERATING methods pay for this; the per-model questions below reflect on the one
     * class they were handed. That split matters because `isNeverDeletable()` is on the authz path
     * for every Filament resource, while `neverDeletable()` is called by gates and the doc dumps.
     *
     * @var array{never: array<class-string, string>, when_unused: array<class-string, array{blocked_by: array<int, string>, instead: string}>, allowed: array<class-string, string>}|null
     */
    private static ?array $registers = null;

    /**
     * @return array{never: array<class-string, string>, when_unused: array<class-string, array{blocked_by: array<int, string>, instead: string}>, allowed: array<class-string, string>}
     */
    private static function registers(): array
    {
        if (self::$registers !== null) {
            return self::$registers;
        }

        $registers = ['never' => [], 'when_unused' => [], 'allowed' => []];

        foreach (glob(app_path('Models/*.php')) ?: [] as $file) {
            $model = 'App\\Models\\'.basename($file, '.php');

            if (! class_exists($model) || ! is_subclass_of($model, Model::class)) {
                continue;
            }

            $reflection = new ReflectionClass($model);

            if ($reflection->isAbstract()) {
                continue;
            }

            // getAttributes() is NOT inherited, which is what we want: a child model must state its
            // own tier rather than quietly adopting a parent's.
            foreach ($reflection->getAttributes(NeverDeletable::class) as $attribute) {
                $registers['never'][$model] = $attribute->newInstance()->correction;
            }

            foreach ($reflection->getAttributes(DeletableWhenUnused::class) as $attribute) {
                $declared = $attribute->newInstance();
                $registers['when_unused'][$model] = [
                    'blocked_by' => $declared->blockedBy,
                    'instead' => $declared->instead,
                ];
            }

            foreach ($reflection->getAttributes(DeletionAllowed::class) as $attribute) {
                $registers['allowed'][$model] = $attribute->newInstance()->reason;
            }
        }

        return self::$registers = $registers;
    }

    /**
     * The three tiers, as maps — the READ API for the whole registry.
     *
     * @return array<class-string, string>
     */
    public static function neverDeletable(): array
    {
        return self::registers()['never'];
    }

    /** @return array<class-string, array{blocked_by: array<int, string>, instead: string}> */
    public static function whenUnused(): array
    {
        return self::registers()['when_unused'];
    }

    /** @return array<class-string, string> */
    public static function allowed(): array
    {
        return self::registers()['allowed'];
    }

    /**
     * Every model that carries a deliberate classification, in tier order.
     *
     * The gate asserts each model on disk appears here — so this must stay the union of the three
     * tiers and never grow a fallback. A model missing from all three is the unclassified case the
     * registry exists to catch. It is deliberately NOT de-duplicated: a model declaring two tiers is
     * an unresolved disagreement, and the gate detects it by counting.
     *
     * @return array<int, class-string>
     */
    public static function classifiedModels(): array
    {
        $registers = self::registers();

        return array_merge(
            array_keys($registers['never']),
            array_keys($registers['when_unused']),
            array_keys($registers['allowed']),
        );
    }

    /**
     * The per-model questions. Each reflects on the single class it was handed rather than building
     * the whole register, so the hot authz path never globs the models directory.
     *
     * @param  class-string  $model
     */
    private static function declared(string $model, string $attribute): ?object
    {
        if (! class_exists($model)) {
            return null;
        }

        $found = (new ReflectionClass($model))->getAttributes($attribute);

        return $found === [] ? null : $found[0]->newInstance();
    }

    /** Is this model deletable only while unreferenced? */
    public static function isDeletableWhenUnused(string $model): bool
    {
        return self::declared($model, DeletableWhenUnused::class) !== null;
    }

    /** The relations that constitute history for this model. */
    public static function blockingRelationsFor(string $model): array
    {
        $declared = self::declared($model, DeletableWhenUnused::class);

        return $declared instanceof DeletableWhenUnused ? $declared->blockedBy : [];
    }

    /** What the operator should do instead of deleting. */
    public static function insteadFor(string $model): ?string
    {
        $declared = self::declared($model, DeletableWhenUnused::class);

        return $declared instanceof DeletableWhenUnused ? $declared->instead : null;
    }

    /** Is this model one the books depend on? */
    public static function isNeverDeletable(string $model): bool
    {
        return self::declared($model, NeverDeletable::class) !== null;
    }

    /**
     * **May the SIGNED-IN actor delete this record?** The one predicate, asked by two layers.
     *
     * `RoleGatedActions::canDelete()` states the rule — a never-deletable model is refused outright,
     * everything else is super_admin only — and every resource in the panel inherits it. What
     * nothing enforced was Filament's OWN `DeleteAction`: in v4 the built-in CRUD actions route
     * their authorization to a Laravel POLICY, this application has none, and
     * `Filament\get_authorization_response()` therefore falls through to `Response::allow()`.
     * `canCreate()`/`canEdit()` survived by accident because the Create and Edit PAGES re-check them
     * on mount; a `DeleteAction` has no page, so a `manager` could delete an unreferenced tenant, a
     * holiday — or a user account. Proven, then fixed at the one seam the actions already pass
     * through: see {@see AnnouncingDeleteAction}.
     *
     * Lives here rather than on the trait because a relation manager's delete button has no
     * resource to ask, and the rule is about the MODEL and the actor, not about the screen.
     */
    public static function actorMayDelete(?Model $record, mixed $livewire = null): bool
    {
        if (! $record instanceof Model) {
            // No record means nothing to authorize against. Refuse: a delete button that cannot say
            // what it is deleting is not one to wave through.
            return false;
        }

        // ── The screen's own answer, when the screen has one ───────────────────────────────────
        // A resource may be MORE permissive than the project default and legitimately so: the
        // portal lets a tenant admin delete their own draft marketing post, which is their content
        // and never a record of anything that happened. Asking the resource keeps that working and
        // keeps `DepartmentResource::canDelete() => false` working too — this seam adds a layer,
        // it does not replace the answers already written.
        //
        // The record's class must match the resource's, or this is a relation manager whose owner
        // resource describes a different model entirely and would be answering the wrong question.
        $fromResource = ResourceAbility::may('canDelete', $livewire, $record);

        if ($fromResource !== null) {
            return $fromResource;
        }

        // ── The floor: the project-wide rule, for a child row with no resource to ask ──────────
        if (self::isNeverDeletable($record::class)) {
            return false;
        }

        return Auth::user()?->hasRole('super_admin') ?? false;
    }

    /** How the operator is told to correct this record instead of deleting it. */
    public static function correctionFor(string $model): ?string
    {
        $declared = self::declared($model, NeverDeletable::class);

        return $declared instanceof NeverDeletable ? $declared->correction : null;
    }

    /**
     * The `{module}.delete` permissions that must no longer exist.
     *
     * Removing the Delete button is the UI half; leaving the permission seeded would let a role be
     * granted a right nothing can exercise, and would quietly restore the button the moment
     * someone re-added the action.
     *
     * Stays a central list on purpose: it is a concrete record of what was RETIRED — the retire
     * migration deletes exactly these rows — not a classification of a model, so there is no model
     * to hang it on. The gate derives the broader invariant from the NEVER tier separately.
     *
     * @var array<int, string>
     */
    private const RETIRED_PERMISSIONS = [
        'invoices.delete',
        'payments.delete',
        'journal_entries.delete',
        'credit_notes.delete',
        'vendor_bills.delete',
        'expenses.delete',
        'deposit_transactions.delete',
        'payrolls.delete',
        'post_dated_cheques.delete',
    ];

    /** @return array<int, string> */
    public static function retiredPermissions(): array
    {
        return self::RETIRED_PERMISSIONS;
    }
}
