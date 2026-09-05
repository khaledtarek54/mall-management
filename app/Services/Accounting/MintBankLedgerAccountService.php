<?php

namespace App\Services\Accounting;

use App\Models\AccountMapping;
use App\Models\BankAccount;
use App\Models\LedgerAccount;
use App\Support\CashFlowSection;

/**
 * Give a bank account a chart leaf of its own.
 *
 * A single-action service rather than a method on {@see BankAccount}, which is where it first
 * landed: it WRITES a different aggregate — a row in the chart of accounts, the accountant's own
 * register — and that is business logic, not something a bank account knows about itself.
 * {@see BankAccount::defaultFor()} stays on the model because it only READS.
 *
 * The rule it serves is {@see BankAccount::assertLedgerAccountIsItsOwn()}: each bank account maps to
 * a chart account nothing else uses. That is the market standard — Yardi's Bank record points at one
 * cash GL account and reconciles OF it, and NetSuite, QuickBooks and Odoo each make a bank account
 * its own GL account — and it is a rule with teeth, so an operator meeting it needs a way through
 * rather than a trip to the chart screen to work out which code comes next. Odoo creates the account
 * for you when you add the bank; this is that.
 */
class MintBankLedgerAccountService
{
    /**
     * Mint a dedicated chart leaf for a bank account — Odoo's behaviour, and the reason
     * {@see BankAccount::assertLedgerAccountIsItsOwn()} is a help rather than an obstacle.
     *
     * Anchored on the PARENT OF THE `bank` ROLE ACCOUNT, which is this install's own answer to
     * "where do we keep bank accounts in the chart" — never a hardcoded `11102`, because the real
     * Egyptian chart has not been supplied and any literal here would be a guess about somebody
     * else's numbering.
     *
     * The code width is taken from the siblings that already exist, so an install on 8-digit codes
     * and one on 10-digit codes each get a leaf that looks like its neighbours. That question is
     * open (see `docs/STATUS.md`), and deriving it is how this survives the answer.
     *
     * Returns null when the chart cannot say where banks live — an unmapped `bank` role, or a role
     * account with no parent. Refusing to guess is right: inventing a top-level account would put a
     * bank somewhere the accountant never agreed to.
     */
    public function mint(string $name, ?int $assetId = null, ?string $nameAr = null): ?LedgerAccount
    {
        $role = AccountMapping::query()
            ->where('key', 'bank')
            ->where(fn ($q) => $q->where('asset_id', $assetId)->orWhereNull('asset_id'))
            ->orderByRaw('case when asset_id is null then 1 else 0 end')
            ->value('ledger_account_id');

        $parent = LedgerAccount::find($role)?->parent;

        if ($parent === null) {
            return null;
        }

        $siblings = LedgerAccount::withTrashed()
            // `withTrashed()` is load-bearing, not tidiness: `ledger_accounts.code` is a PLAIN unique
            // index, so a soft-deleted `…002` still occupies that code while the SoftDeletes global
            // scope hides it from this query. Without it, retiring an account makes the next mint
            // propose the code it just freed in appearance only — a duplicate-key 500 on a button
            // whose whole job is to be the easy path.
            ->where('parent_id', $parent->id)
            ->pluck('code')
            ->filter(fn (string $code) => str_starts_with($code, $parent->code) && ctype_digit($code));

        // Match the neighbours. With none, three digits is the shape every seeded chart here uses.
        $width = $siblings->map(fn (string $c) => strlen($c) - strlen($parent->code))->max() ?: 3;

        $next = ($siblings->map(fn (string $c) => (int) substr($c, strlen($parent->code)))->max() ?? 0) + 1;

        return LedgerAccount::create([
            // `parent_id` and `normal_balance` are DERIVED in `LedgerAccount::saving` from the code
            // and the type — passing either here would be a second, conflicting truth.
            'code' => $parent->code.str_pad((string) $next, $width, '0', STR_PAD_LEFT),
            'name_en' => $name,
            // The operator's create-option form asks for ONE name, so the Arabic falls back to it
            // rather than leaving the column blank — a chart account with no Arabic name renders as
            // an empty cell on the Arabic panel, which reads as missing data rather than as a name
            // nobody supplied. A caller that HAS both (a seeder) passes both.
            'name_ar' => $nameAr ?? $name,
            'type' => 'asset',
            // A bank's own leaf IS cash on the cash-flow statement. `CashFlowSection::for()` floors
            // an unclassified asset to OPERATING — the safer error for working capital, and exactly
            // the wrong one for the account whose whole identity is "money in the bank": left null,
            // every receipt routed through a minted bank read as an operating working-capital
            // movement while only the generic `bank` role account counted as cash. The statement
            // still balanced, which is why nothing said so (2026-09-05).
            'cash_flow_section' => CashFlowSection::CASH,
            'is_postable' => true,
            'is_active' => true,
        ]);
    }
}
