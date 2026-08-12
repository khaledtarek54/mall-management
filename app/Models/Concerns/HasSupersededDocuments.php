<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A compliance file keeps its history — so "is this party compliant?" is a question about the
 * CURRENT certificate of each type, never about every row on file.
 *
 * Without this distinction, renewing a certificate the honest way — upload the new one, keep the
 * lapsed one as the record of what was in force last year — makes the party permanently
 * non-compliant, because the lapsed row never stops existing. For a vendor that is not a warning:
 * `Vendor::scopeAssignable()` drops it from every picker and `MaintenanceWorkOrder::saving()`
 * refuses it, so **doing the paperwork correctly bricks the contractor**, and the only escape is
 * deleting the evidence. It also stopped that contractor's preventive-maintenance plans generating
 * at all — a statutory lift round that silently never happens.
 *
 * Ordering, most-current first: an **open-ended** certificate (no expiry recorded) outranks any
 * dated one, because `hasExpired()` already treats a missing expiry as "never lapses" and a row
 * that cannot lapse must be at least as current as one that can. Otherwise the one that runs
 * **longest** is current — not the most recently entered, since a back-dated correction typed after
 * the renewal should not displace it. Ties break on `id`, so exactly one row per type is current
 * and the predicate is a total order rather than a set that can return two answers.
 *
 * The fail-open on a blank expiry is deliberate and consistent with the module's existing stance
 * (a party with NO certificate on file is not treated as lapsed either — v1 does not retro-demand
 * paperwork from every supplier; blacklist one to hard-block it).
 */
trait HasSupersededDocuments
{
    /** The column naming the party this document belongs to — `vendor_id`, `tenant_id`, … */
    abstract public function documentOwnerColumn(): string;

    /**
     * The one live document of each type — everything a later certificate has replaced is excluded.
     *
     * Expressed in SQL rather than in PHP so the *same* predicate serves both the per-record
     * question (`$vendor->hasExpiredBlockingDocument()`) and the set question
     * (`Vendor::assignable()`). Those two have to agree: a vendor the picker offers and the save
     * guard then refuses is worse than either being wrong alone.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeCurrent(Builder $query): Builder
    {
        $table = $this->getTable();
        $owner = $this->documentOwnerColumn();
        $softDeletes = in_array(SoftDeletes::class, class_uses_recursive(static::class), true);

        return $query->whereNotExists(function ($sub) use ($table, $owner, $softDeletes) {
            $sub->selectRaw('1')
                ->from($table.' as newer')
                ->whereColumn('newer.'.$owner, $table.'.'.$owner)
                ->whereColumn('newer.type', $table.'.type')
                ->whereColumn('newer.id', '!=', $table.'.id');

            if ($softDeletes) {
                // The outer query's global scope does not reach in here, and a document somebody
                // deleted must not go on superseding the one they kept.
                $sub->whereNull('newer.deleted_at');
            }

            $sub->where(function ($w) use ($table) {
                $w
                    // An open-ended certificate outranks a dated one.
                    ->where(fn ($o) => $o
                        ->whereNull('newer.expires_on')
                        ->whereNotNull($table.'.expires_on'))
                    // Otherwise the one that runs longest is current.
                    ->orWhere(fn ($o) => $o
                        ->whereNotNull('newer.expires_on')
                        ->whereNotNull($table.'.expires_on')
                        ->whereColumn('newer.expires_on', '>', $table.'.expires_on'))
                    // Same expiry (or neither dated): the later-entered row wins, so the order is
                    // total and exactly one row per type survives.
                    ->orWhere(fn ($o) => $o
                        ->where(fn ($e) => $e
                            ->where(fn ($b) => $b
                                ->whereNull('newer.expires_on')
                                ->whereNull($table.'.expires_on'))
                            ->orWhereColumn('newer.expires_on', '=', $table.'.expires_on'))
                        ->whereColumn('newer.id', '>', $table.'.id'));
            });
        });
    }

    /**
     * Everything a later certificate of the same type has replaced — the history, kept on file.
     *
     * The exact complement of `current()`, so the two can never both miss a row.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeSuperseded(Builder $query): Builder
    {
        return $query->whereNot(fn (Builder $q) => $q->current());
    }

    /** Has a later certificate of this type replaced this one? */
    public function isSuperseded(): bool
    {
        return static::query()->whereKey($this->getKey())->superseded()->exists();
    }
}
