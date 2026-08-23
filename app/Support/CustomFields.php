<?php

namespace App\Support;

use App\Models\CustomField;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Which record types carry user-defined fields, and what they are (D-7 / EG-32).
 *
 * ## The register is EXPLICIT, and that is the point
 *
 * Storage is each record's own nullable `metadata` JSON column, which five tables have carried
 * since the first migrations and nothing has ever read. It would be possible to derive this list by
 * asking the schema which tables have such a column — and that is exactly the wrong shape, because
 * `metadata` is `fillable` on every one of those models. A derived register would mean the day
 * someone adds a `metadata` column for an unrelated purpose, that model silently starts accepting
 * operator-defined keys through its form. Whether a record type is extensible is a decision, so it
 * is written down, with the reason it was made.
 *
 * ## Only known keys are ever written
 *
 * {@see App\Models\Concerns\HasCustomFields::fillCustomFields()} writes ONLY keys this register
 * currently defines for that model, and merges into whatever the column already holds. `metadata`
 * being fillable means a crafted Livewire payload could otherwise set arbitrary keys on the record,
 * and a JSON column will accept every one of them without complaint.
 *
 * ## Definitions are memoised per REQUEST, never in a static
 *
 * A `queue:work` daemon outlives the request; a static would hand a month-old catalogue to every
 * job it ran after the operator added a field. {@see CustomField} flushes on write. Same shape as
 * `PayrollRates`, `Vat` and the code catalogues.
 */
final class CustomFields
{
    private const MEMO = 'atriom.custom_fields.definitions';

    /**
     * The record types an operator may extend, each with why.
     *
     * Keyed by MORPH ALIAS — the vocabulary `App\Support\MorphMap` governs — so a namespace move
     * cannot orphan a definition.
     *
     * These five are the long-lived MASTER records an operator accumulates their own facts about.
     * Four already carried a `metadata` column; `units` gained one, because the shop is the record
     * a mall records the most about and was the only master record with nowhere to put it.
     *
     * Deliberately absent are the money documents — an invoice, a payment, a journal entry. Those
     * are evidence, and an operator-defined field on one is a place to record something that
     * belongs on a document nobody can reconstruct later; the codebase already refuses to let them
     * be deleted for the same reason. `departments` is absent too despite having the column: an
     * internal org unit is not what an operator extends, and if that proves wrong it is one line
     * here rather than a migration.
     *
     * @var array<string, string>
     */
    public const EXTENSIBLE = [
        'tenant' => 'The retailer. The record an operator accumulates the most of their own facts about — parent group, buying office, franchise partner, the reference their own finance team files them under.',
        'lease' => 'The contract. Brokers, negotiation references, landlord-works numbers and clause flags that are real terms of a specific deal and not a column every lease should have.',
        'unit' => 'The shop. Physical facts a particular mall tracks — shutter type, grease-trap access, the landlord-works reference — that would be dead columns in every other mall.',
        'vendor' => 'The supplier. Approved-list status, trade-licence references and the classifications a principal is asked to evidence when it deals with a government client.',
        'asset' => 'The property itself. A portfolio grows registration numbers, licence references and ownership facts that differ per mall and per owner.',
    ];

    /**
     * Every active definition for a model, in the operator's own order.
     *
     * @return Collection<int, CustomField>
     */
    public static function for(string $morphAlias): Collection
    {
        return self::all()->get($morphAlias, collect())->where('is_active', true)->values();
    }

    /**
     * Every definition for a model, active or not.
     *
     * The DISPLAY side reads this: deactivating a field stops it being offered on the form, and
     * must never blank a value already recorded — a field retired half way through a year still
     * explains what is on the records that carry it.
     *
     * @return Collection<int, CustomField>
     */
    public static function including(string $morphAlias, bool $inactive = true): Collection
    {
        $all = self::all()->get($morphAlias, collect());

        return ($inactive ? $all : $all->where('is_active', true))->values();
    }

    /** Is this record type one an operator may extend at all? */
    public static function isExtensible(string $morphAlias): bool
    {
        return array_key_exists($morphAlias, self::EXTENSIBLE);
    }

    /** Drop the per-request memo. Called from {@see CustomField}'s saved/deleted hooks. */
    public static function flush(): void
    {
        app()->forgetInstance(self::MEMO);
    }

    /**
     * Every definition, grouped by model. One query for a whole request.
     *
     * @return Collection<string, Collection<int, CustomField>>
     */
    private static function all(): Collection
    {
        if (app()->has(self::MEMO)) {
            return app(self::MEMO);
        }

        // The table is missing for the window every install passes through — `atriom:install` and
        // the test suite both build records before this migration has run — and a resolver that
        // fataled before its own migration would make the migration unrunnable.
        $definitions = Schema::hasTable('custom_fields')
            ? CustomField::query()->orderBy('sort_order')->orderBy('id')->get()->groupBy('model')
            : collect();

        app()->instance(self::MEMO, $definitions);

        return $definitions;
    }
}
