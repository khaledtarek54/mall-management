<?php

namespace App\Models\Concerns;

use App\Support\DocumentNumbering;

/**
 * The short, quotable number a counterparty is known by — `TN-0000042`, `VN-0000018`.
 *
 * ## Why a party needs one at all
 *
 * Everything else in this system already had one. A unit is `A-114`, an employee is `EMP-0042`, a
 * lease is `LSE-AW-2026-0001`, an invoice is `INV-AW-202608-0110`. The two records people actually
 * talk about — the retailer and the supplier — were identified by name alone, and a name is the
 * one identifier that is neither unique, nor short, nor spelled the same way twice. «Zara», «Zara
 * Home» and «Zara Kids» are three tenants; «شركة الفتح» and «شركه الفتح» are one, typed by two
 * people. An operator on the phone had nothing to read out, and an operator at a search box had
 * nothing to type that could only mean one record.
 *
 * This is also what Yardi, MRI and Entrata all do — the tenant code is the primary handle in each
 * of them — so an operator arriving from any of those expects the field to exist and will ask
 * where it went.
 *
 * ## Allocated like a document number, on purpose
 *
 * Through `AllocatesDocumentNumber`, which means the read-then-write is serialised by a cache lock
 * held ACROSS the insert. Party creation looks like a low-traffic path where that would be
 * over-engineering, right up until an import runs while a leasing officer saves a new tenant — and
 * the failure mode is not a duplicate code but a duplicate-key 500 in the middle of an import,
 * because the UNIQUE index is doing the work the code should have been.
 *
 * ## A supplied code always wins
 *
 * Allocation only happens when `code` is blank. That is not politeness: an operator migrating off
 * another system arrives with codes their accountant, their bank files and their own paperwork
 * already use, and renumbering those on import would break every reconciliation they own. The
 * importers map the column, so a CSV carrying `code` keeps it.
 *
 * The consequence is that the sequence can meet a code it did not write. `generatePartyCode()`
 * reads the highest code lexically, which a non-conforming import (`TN-ZARA`) sorts above — the
 * `(int)` cast then yields 0 and the collision loop walks up from 1 to the first free number. Slow
 * in that pathological case, correct in all of them; the alternative is a driver-specific REGEXP
 * in a hot path to avoid a loop that runs on a table with tens of rows.
 */
trait AllocatesPartyCode
{
    use AllocatesDocumentNumber;

    /**
     * The `DocumentNumbering::TYPES` key this model numbers from — so the operator can change the
     * prefix from Settings without a deploy, exactly as they can for an invoice.
     */
    abstract public static function partyCodeType(): string;

    public static function bootAllocatesPartyCode(): void
    {
        static::creating(function (self $model): void {
            if (filled($model->code)) {
                return;
            }

            $model->code = $model->allocateDocumentNumber(
                static::partyCodePrefix(),
                fn (): string => static::generateUniquePartyCode(),
            );
        });
    }

    public static function partyCodePrefix(): string
    {
        return DocumentNumbering::prefixFor(static::partyCodeType()).'-';
    }

    /**
     * The next code in the series.
     *
     * MAX-based over `withTrashed()`, never `count() + 1` — a soft-deleted row keeps its code
     * reserved in the UNIQUE index while falling out of the count, which is the bug that once made
     * lease creation fail for a whole calendar year (see `Lease::generateReference()`).
     *
     * Seven digits, which is more than a mall will ever need and exactly the point: the width must
     * never change, because a fixed width is what makes the lexical `ORDER BY` above equal a
     * numeric one.
     */
    public static function generatePartyCode(): string
    {
        $prefix = static::partyCodePrefix();

        $last = static::withTrashed()
            ->where('code', 'like', $prefix.'%')
            ->orderByDesc('code')
            ->value('code');

        $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return sprintf('%s%07d', $prefix, $next);
    }

    /** MAX+1 with a collision loop — the belt to the allocation lock's braces. */
    protected static function generateUniquePartyCode(): string
    {
        $prefix = static::partyCodePrefix();
        $candidate = static::generatePartyCode();
        $attempts = 0;

        while (static::withTrashed()->where('code', $candidate)->exists()) {
            $candidate = sprintf('%s%07d', $prefix, (int) substr($candidate, strlen($prefix)) + 1);

            if (++$attempts > 1000) {
                // Give up on the series rather than spin: the UNIQUE index will refuse a genuine
                // duplicate, which is a clear error, where an infinite loop is a hung request.
                return $prefix.uniqid();
            }
        }

        return $candidate;
    }
}
