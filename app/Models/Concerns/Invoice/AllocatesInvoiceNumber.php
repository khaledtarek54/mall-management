<?php

namespace App\Models\Concerns\Invoice;

use App\Support\DocumentNumbering;
use Illuminate\Support\Carbon;

/**
 * **How an invoice number is composed and made unique.**
 *
 * Pure statics over the numbering scheme — no instance state, no events.
 *
 * **The `creating` closure that calls these deliberately did NOT move with them.** It stays in
 * `Invoice::booted()` because it reaches protected members, and because the number must be assigned
 * on `creating` for two separate reasons: the advisory lock has to span the INSERT, and
 * `HasSearchText` folds the blob on `created` — a number allocated any later would be missing from
 * the very blob that makes the invoice findable by it.
 *
 * So this trims the file without shortening `booted()`, which is the honest description of what it
 * achieves.
 */
trait AllocatesInvoiceNumber
{
    /**
     * The number prefix for this document's sequence — ONE definition, used by generateNumber()
     * and by the allocation lock key (see AllocatesDocumentNumber). Two copies would drift, and a
     * lock keyed on a prefix that no longer matches the sequence it guards protects nothing.
     */
    public static function numberPrefix(string $assetCode = 'AW', ?\DateTimeInterface $issueDate = null): string
    {
        $issueDate = $issueDate ? Carbon::instance($issueDate) : now();

        return sprintf('%s-%s-%s-', DocumentNumbering::prefixFor('invoice'), $assetCode, $issueDate->format('Ym'));
    }

    public static function generateNumber(string $assetCode = 'AW', ?\DateTimeInterface $issueDate = null): string
    {
        $prefix = static::numberPrefix($assetCode, $issueDate);

        $last = static::withTrashed()
            ->where('number', 'like', $prefix.'%')
            ->orderByDesc('number')
            ->value('number');

        $next = $last
            ? ((int) substr($last, strlen($prefix))) + 1
            : 1;

        return sprintf('%s%04d', $prefix, $next);
    }

    protected static function generateUniqueNumber(string $assetCode = 'AW', ?\DateTimeInterface $issueDate = null): string
    {
        $candidate = static::generateNumber($assetCode, $issueDate);

        $attempts = 0;
        while (static::withTrashed()->where('number', $candidate)->exists()) {
            $attempts++;
            if ($attempts > 100) {
                throw new \RuntimeException('Unable to allocate a unique invoice number after 100 attempts.');
            }
            $issue = $issueDate ? Carbon::instance($issueDate) : now();
            $prefix = sprintf('%s-%s-%s-', DocumentNumbering::prefixFor('invoice'), $assetCode, $issue->format('Ym'));
            $n = ((int) substr($candidate, strlen($prefix))) + 1;
            $candidate = sprintf('%s%04d', $prefix, $n);
        }

        return $candidate;
    }
}
