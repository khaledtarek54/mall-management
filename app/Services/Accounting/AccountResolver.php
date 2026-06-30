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
            throw new \DomainException("No account mapping for role '{$key}'.");
        }

        $account = $mapping->account;
        if (! $account) {
            throw new \DomainException("Account mapping '{$key}' points to a missing account.");
        }
        if (! $account->is_postable) {
            throw new \DomainException("Account mapping '{$key}' points to a non-postable account ({$account->code}).");
        }

        return $this->cache[$cacheKey] = $account;
    }

    /** Convenience: resolve to the account id only. */
    public function id(string $key, ?int $assetId = null): int
    {
        return $this->account($key, $assetId)->id;
    }
}
