<?php

use App\Models\CamAllocation;
use App\Models\CamExpensePool;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Services\CamReconciliationService;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * Regression: A LEASE MAY CARVE NAMED ACCOUNTS OUT OF ITS OWN SHARE (slice 3).
 *
 * `cam_allocations.exclusions` existed from the day the table was created — fillable, cast to array,
 * and read by NOTHING. Present and inert, and carried in module 08's own gap list as "still unused"
 * for the whole of its life. What was missing was the TERM: an exclusion is a clause in ONE tenant's
 * lease, so it belongs beside the cap and the stated share on `lease_cam_terms`, which is already
 * keyed by (lease, pool, year) — and a clause excluding the grease trap from a CAM share would be
 * meaningless without that.
 *
 * The rule the neighbours rely on: **a carve-out is not redistributed.** Their leases say "your
 * pro-rata share of the pool", so charging them more because a third party negotiated an exclusion
 * would over-bill them against their own terms. The landlord bears it — the same rule a stated share
 * below the area share already follows — and the tie-out needs no help, because
 * `landlord_unrecovered_amount` is `actual − Σ allocated`.
 */
afterEach(fn () => CarbonImmutable::setTestNow());

beforeEach(function () {
    CarbonImmutable::setTestNow('2028-01-15');
    $this->seed(ChartOfAccountsSeeder::class);

    $this->asset = makeAsset(['leasable_area_sqm' => 200]);
    $span = ['commencement_date' => '2024-01-01', 'expiry_date' => '2030-12-31'];

    // Two equal leases, so each takes half and the neighbour is the control.
    $this->a = makeLease(makeUnit($this->asset, ['area_sqm' => 100]), makeTenant(), $span)->fresh();
    $this->b = makeLease(makeUnit($this->asset, ['area_sqm' => 100]), makeTenant(), $span)->fresh();

    // Two expense accounts, posted 60,000 and 40,000 — a 100,000 ledger-sourced pool.
    $this->cleaning = LedgerAccount::where('type', 'expense')->orderBy('code')->skip(2)->first();
    $this->capital = LedgerAccount::where('type', 'expense')->orderBy('code')->skip(3)->first();

    // A BALANCED entry, written as a DRAFT and posted afterwards: `JournalLine` refuses a line on an
    // an entry that is already posted (debits would stop equalling credits), which is the guard
    // doing its job and not something to work around.
    $bank = LedgerAccount::where('type', 'asset')->orderBy('code')->firstOrFail();

    $post = function (LedgerAccount $account, float $amount) use ($bank) {
        $entry = JournalEntry::create([
            'asset_id' => $this->asset->id,
            'entry_date' => '2027-06-30',
            'status' => 'draft',
            'description_en' => 'test',
            'number' => 'JE-'.uniqid(),
        ]);
        foreach ([[$account, $amount, 0], [$bank, 0, $amount]] as [$acc, $dr, $cr]) {
            JournalLine::create([
                'journal_entry_id' => $entry->id, 'ledger_account_id' => $acc->id,
                'asset_id' => $this->asset->id, 'debit' => $dr, 'credit' => $cr,
            ]);
        }
        $entry->update(['status' => 'posted']);
    };
    $this->postTo = $post;

    $post($this->cleaning, 60_000);
    $post($this->capital, 40_000);

    $this->pool = function (): CamExpensePool {
        $pool = CamExpensePool::create([
            'asset_id' => $this->asset->id, 'period_year' => 2027,
            'pool_code' => CamExpensePool::CODE_CAM, 'status' => 'draft',
            'total_actual_expense' => 100_000, 'total_estimated_collected' => 0,
            'expense_basis' => CamExpensePool::BASIS_LEDGER, 'estimate_basis' => 'stated',
            'admin_fee_pct' => 0,
        ]);
        $pool->ledgerAccounts()->sync([$this->cleaning->id, $this->capital->id]);

        return $pool->fresh();
    };

    $this->allocated = fn (CamExpensePool $pool, $lease) => (float) CamAllocation::query()
        ->where('cam_expense_pool_id', $pool->id)->where('lease_id', $lease->id)->sole()->allocated_amount;
});

it('carves the excluded accounts out of that lease share and nobody else\'s', function () {
    $this->a->camTerms()->create([
        'effective_year' => 2027,
        'excluded_account_ids' => [$this->capital->id],   // 40,000 of the 100,000 pool
    ]);

    $pool = ($this->pool)();
    app(CamReconciliationService::class)->generateAllocations($pool);

    // A pays half of (100,000 − 40,000); B pays half of the WHOLE pool, because B's own lease says
    // "your pro-rata share of the pool" and was not renegotiated.
    expect(($this->allocated)($pool, $this->a))->toBe(30_000.0)
        ->and(($this->allocated)($pool, $this->b))->toBe(50_000.0);

    // The 20,000 A no longer bears is the landlord's, and the tie-out still holds exactly.
    $pool->refresh();
    expect(round(
        (float) $pool->allocations()->sum('allocated_amount') + (float) $pool->landlord_unrecovered_amount, 2
    ))->toBe(100_000.0);
});

it('reports the carve-out as its OWN cause, not as vacancy', function () {
    $this->a->camTerms()->create(['effective_year' => 2027, 'excluded_account_ids' => [$this->capital->id]]);

    $pool = ($this->pool)();
    app(CamReconciliationService::class)->generateAllocations($pool);

    // 40,000 excluded × A's 50% share = the 20,000 A would otherwise have borne. Reported under its
    // own heading, because "vacancy" says CHANGE THE DENOMINATOR about money a clause removed.
    expect($pool->fresh()->landlordShare())
        ->toMatchArray(['exclusions' => 20_000.0, 'caps' => 0.0, 'vacancy' => 0.0, 'total' => 20_000.0]);
});

it('changes nothing at all when no lease excludes anything', function () {
    // The parity case every clause in this module has to pass: the default must be byte-identical.
    $pool = ($this->pool)();
    app(CamReconciliationService::class)->generateAllocations($pool);

    expect(($this->allocated)($pool, $this->a))->toBe(50_000.0)
        ->and(($this->allocated)($pool, $this->b))->toBe(50_000.0)
        ->and((float) CamAllocation::where('cam_expense_pool_id', $pool->id)->sum('excluded_amount'))->toBe(0.0)
        ->and($pool->fresh()->landlordShare()['exclusions'])->toBe(0.0);
});

it('ignores an account this pool does not contain', function () {
    // An account no pool holds carves out nothing. Without the intersection it would reach into the
    // ledger for a cost this pool never included and reduce the share against money that was never
    // in it — a clause that looks configured and is quietly wrong.
    $outside = LedgerAccount::where('type', 'expense')
        ->whereNotIn('id', [$this->cleaning->id, $this->capital->id])->firstOrFail();

    // IT MUST HOLD REAL SPEND, or the test proves nothing: an unposted account nets to zero whether
    // or not the intersection exists, and the first version of this passed with the guard deleted.
    ($this->postTo)($outside, 25_000);

    $this->a->camTerms()->create(['effective_year' => 2027, 'excluded_account_ids' => [$outside->id]]);

    $pool = ($this->pool)();
    app(CamReconciliationService::class)->generateAllocations($pool);

    expect(($this->allocated)($pool, $this->a))->toBe(50_000.0);
});

it('resolves the carve-out per POOL, like the cap it sits beside', function () {
    // A clause excluding capital items from CAM says nothing about the food-court pool.
    $this->a->camTerms()->create([
        'effective_year' => 2027, 'pool_code' => CamExpensePool::CODE_CAM,
        'excluded_account_ids' => [$this->capital->id],
    ]);

    $cam = ($this->pool)();
    app(CamReconciliationService::class)->generateAllocations($cam);
    expect(($this->allocated)($cam, $this->a))->toBe(30_000.0);

    $grease = CamExpensePool::create([
        'asset_id' => $this->asset->id, 'period_year' => 2027, 'pool_code' => 'fc_grease',
        'status' => 'draft', 'total_actual_expense' => 100_000, 'total_estimated_collected' => 0,
        'expense_basis' => CamExpensePool::BASIS_LEDGER, 'estimate_basis' => 'stated', 'admin_fee_pct' => 0,
    ]);
    $grease->ledgerAccounts()->sync([$this->cleaning->id, $this->capital->id]);

    app(CamReconciliationService::class)->generateAllocations($grease->fresh());
    expect(($this->allocated)($grease, $this->a))->toBe(50_000.0);
});
