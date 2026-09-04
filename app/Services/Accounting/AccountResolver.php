<?php

namespace App\Services\Accounting;

use App\Models\AccountMapping;
use App\Models\LedgerAccount;

/**
 * Resolves a semantic posting role (e.g. "accounts_receivable") to a real chart
 * account via the account_mappings table. A per-property mapping (asset_id) wins
 * over the global default (null asset_id). Fails loudly if a role is unmapped or
 * points at a non-postable account — a mis-wired posting must never silently
 * land on the wrong account.
 */
class AccountResolver
{
    /** @var array<string, LedgerAccount> in-request cache keyed by "key:assetId" */
    protected array $cache = [];

    public function account(string $key, ?int $assetId = null): LedgerAccount
    {
        $cacheKey = $key.':'.($assetId ?? 'global');
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $mapping = null;
        if ($assetId !== null) {
            $mapping = AccountMapping::query()
                ->where('key', $key)
                ->where('asset_id', $assetId)
                ->orderBy('id')
                ->first();
        }
        // Global default. orderBy('id') makes resolution deterministic even if a
        // duplicate (key, NULL) row slips in (the unique index can't catch NULLs).
        $mapping ??= AccountMapping::query()
            ->where('key', $key)
            ->whereNull('asset_id')
            ->orderBy('id')
            ->first();

        if (! $mapping) {
            throw new \DomainException(__('admin.refusals.map_missing', ['role' => $key]));
        }

        $account = $mapping->account;
        if (! $account) {
            throw new \DomainException(__('admin.refusals.map_account_missing', ['role' => $key]));
        }
        if (! $account->is_postable) {
            throw new \DomainException(__('admin.refusals.map_account_not_postable', ['role' => $key, 'code' => $account->code]));
        }

        return $this->cache[$cacheKey] = $account;
    }

    /**
     * EVERY chart account this role currently points at — the global default AND each per-property
     * override.
     *
     * {@see account()} answers "where does THIS document post", one property at a time, and is
     * right for that. A CONTROL TOTAL is the other question: the AR/AP and deposits tie-outs
     * compare the general ledger against what the SOURCE documents imply PORTFOLIO-WIDE, so they
     * have to read every account the role resolves to anywhere.
     *
     * Reading only the global default was the defect (SW-143). The posting map offers a per-mall
     * override on a control of its own (`PropertyField::scope()`) and every journalizer passes the
     * document's `asset_id` into {@see account()} — so one such row for `accounts_receivable` sends
     * that mall's invoices to a different account while the tie-out keeps comparing the global one
     * against EVERY invoice in the portfolio. The delta is that mall's whole receivable, it never
     * clears, `books_tie_out` is red for ever, and `atriom:preflight` blocks the next deploy for a
     * reason that has nothing to do with the deploy. Measured on the dev and QA databases
     * (2026-09-04): 52 mappings, 0 of them property-scoped — a supported configuration nobody has
     * used yet, not a live fault.
     *
     * Postability is deliberately NOT re-checked here, unlike {@see account()}. That method asks
     * where the next entry MAY post; this one asks where a role's money HAS landed, and an account
     * retired since it was posted to still carries the balance a tie-out must account for.
     *
     * @return array<int, LedgerAccount> keyed by account id; empty when the role is unmapped
     */
    public function accountsFor(string $key): array
    {
        $ids = AccountMapping::query()
            ->where('key', $key)
            ->pluck('ledger_account_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $ids === []
            ? []
            : LedgerAccount::query()->whereKey($ids)->get()->keyBy('id')->all();
    }

    /** Convenience: resolve to the account id only. */
    public function id(string $key, ?int $assetId = null): int
    {
        return $this->account($key, $assetId)->id;
    }
}
