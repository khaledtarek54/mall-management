<?php

namespace App\Support;

use App\Models\AccountingPeriod;
use App\Models\Asset;
use App\Models\ChargeCode;
use App\Models\Employee;
use App\Models\LedgerAccount;
use App\Models\Payroll;
use App\Models\TaxCode;
use App\Models\Vendor;
use App\Settings\TaxSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * What is not configured yet, and what each gap actually breaks.
 *
 * `App\Support\Health` answers "is this deployment alive" — database, queue, scheduler, backups.
 * This answers a different question that nothing on any screen answered: **is it set up**. The two
 * are unrelated failures. A perfectly healthy installation bills every tenant through a floor rate
 * because nobody classified the charge codes, and issues tax invoices with no registration number
 * on them, and neither shows up as an outage.
 *
 * [docs/operations/GO-LIVE.md](../../docs/operations/GO-LIVE.md) is that list, verified by hand against the code. Hand
 * verification does not survive: it was accurate on the day it was written and every item on it can
 * fall out of date silently. These checks read the live database.
 *
 * ## The impact line is the feature
 *
 * A checklist of red dots is a nag. What makes one worth opening is each row saying **what happens
 * if you leave it** — "tenants cannot reclaim the VAT you charged them" is a sentence somebody
 * acts on; "seller_tax_registration_number is empty" is one they close.
 *
 * ## Severity is about money and law, not tidiness
 *
 * `blocking` means something is wrong that reaches a tenant, the books or the tax authority.
 * `advisory` means the system is working and could work better. Nothing here is fatal — an
 * unconfigured Atriom still bills correctly on its defaults, which is the whole design — so this
 * page reports rather than refuses.
 */
class ConfigurationHealth
{
    public const BLOCKING = 'blocking';

    public const ADVISORY = 'advisory';

    public const TAX = 'tax';

    public const ACCOUNTING = 'accounting';

    public const BILLING = 'billing';

    public const PAYROLL = 'payroll';

    /** @var array<int, string> */
    public const CATEGORIES = [self::TAX, self::ACCOUNTING, self::BILLING, self::PAYROLL];

    /**
     * Every configuration check, in the order an operator should work through them.
     *
     * @return array<int, array{key: string, category: string, severity: string, ok: bool, detail: string}>
     */
    public static function run(): array
    {
        return [
            self::sellerTaxIdentity(),
            self::billingContact(),
            self::chargeCodesClassified(),
            self::taxCodesCommissioned(),
            self::withholdingConfigured(),
            self::postingMapComplete(),
            self::openAccountingPeriod(),
            self::payrollRatesConfigured(),
        ];
    }

    /**
     * Payroll ships every statutory rate at zero, and nothing anywhere said so.
     *
     * **A zero rate is not, by itself, a fault** — the settings screen's own help offers "leave at 0
     * and enter it per employee" as a supported way to work, and a checklist row that contradicts the
     * field help beside it teaches the operator to ignore the page. So this fires on the OUTCOME
     * nobody sees: an approved payroll month that withheld nothing at all, which put net = gross on
     * every payslip and no liability in the books while looking exactly like a working run.
     *
     * The full reasoning — why a month rather than a run, why per property, why not in the future —
     * is in [modules/24](../../docs/modules/24-hr-employees.md) and not repeated here. Two traps are,
     * because both are invisible at the call site:
     *
     *   - **Not gated on a module flag.** `PayrollResource` derives its module key as `payrolls`
     *     (`RoleGatedActions::permissionModule()` pluralises the model), which is not in
     *     {@see Modules::KEYS} and is therefore always enabled. An early return on the `employees`
     *     toggle would silence this check while payroll stayed fully reachable.
     *   - **An approved run's amounts are frozen** ({@see Payroll::booted()} refuses a dirty
     *     `salary_tax` once `status` was `approved`), so the remedy has to be a second document.
     *     That is why the question is asked of the MONTH. The remedy is to CANCEL the run and
     *     re-issue it with the deductions — not to raise a second one alongside it, which
     *     {@see Payroll::booted()} refuses outright ("approving this run would give N employees a
     *     SECOND approved payslip for this month"). The row then clears because the month's latest
     *     approved run is the corrected one.
     */
    private static function payrollRatesConfigured(): array
    {
        $assetIds = AssignedAssets::idsForCurrentUser();

        // `Payroll` is `#[PropertyOwned(portfolioRowsWhenNull: true)]` and `payrolls.asset_id` is
        // nullable, so a head-office run belongs to everyone; a bare `whereIn` excludes NULL and
        // would hide exactly the run nobody owns. `employees.asset_id` is NOT NULL, so it is strict.
        $scope = fn (Builder $query, bool $portfolioRowsToo = false): Builder => $assetIds === null
            ? $query
            : $query->where(fn (Builder $inner) => $portfolioRowsToo
                ? $inner->whereIn('asset_id', $assetIds)->orWhereNull('asset_id')
                : $inner->whereIn('asset_id', $assetIds));

        $rosterProperties = $scope(Employee::query()->active())->distinct()->pluck('asset_id');

        if ($rosterProperties->isEmpty()) {
            return self::check('payroll_rates_configured', self::PAYROLL, self::ADVISORY, true,
                __('admin.config_health.payroll_no_roster'));
        }

        // Exact equality against the value `MAX()` returned, never `whereDate()`: that compares a
        // formatted `Y-m-d` against the raw bound value and silently matches nothing when the value
        // carries a time, which is how the first cut of this check reported green on a broken run.
        // The upper bound is EXCLUSIVE against next month's first day for the same reason — an
        // inclusive `<= endOfMonth()` compares 10 characters against the 19 the date cast writes,
        // which is true on MySQL and false on sqlite for a run dated the last day of the month.
        $approved = fn (): Builder => $scope(Payroll::query()->where('status', 'approved'), true);
        // `startOfMonth()` FIRST. `addMonth()` on the 29th–31st overflows — 2026-08-31 + 1 month is
        // 2026-10-01, verified against this project's Carbon — so on seven days of 2026 the bound
        // was the month AFTER next and a genuinely future payroll month was admitted as "latest",
        // hiding a broken current month. That is a false negative in the clamp's own class, and the
        // `endOfMonth()` expression this replaced could never produce it.
        $nextMonth = CarbonImmutable::now()->startOfMonth()->addMonth()->toDateString();

        $latestPerProperty = $approved()
            ->where('period_month', '<', $nextMonth)
            ->groupBy('asset_id')
            ->select('asset_id')
            ->selectRaw('MAX(period_month) as latest_period')
            ->get();

        $offending = [];

        foreach ($latestPerProperty as $row) {
            // One round trip per property, not two: "did anything withhold" and "how many runs paid
            // gross" are the same aggregate over the same rows.
            $tally = $approved()
                ->where('period_month', $row->latest_period)
                ->when($row->asset_id === null,
                    fn (Builder $q) => $q->whereNull('asset_id'),
                    fn (Builder $q) => $q->where('asset_id', $row->asset_id))
                ->selectRaw('SUM(CASE WHEN salary_tax > 0 OR social_insurance > 0 OR employer_social_insurance > 0 THEN 1 ELSE 0 END) as withheld')
                ->selectRaw('SUM(CASE WHEN gross_salaries > 0 THEN 1 ELSE 0 END) as paying')
                ->first();

            if ((int) $tally->withheld === 0 && (int) $tally->paying > 0) {
                $offending[] = ['asset_id' => $row->asset_id, 'period' => $row->latest_period, 'runs' => (int) $tally->paying];
            }
        }

        if ($offending !== []) {
            // WHICH mall and WHICH month. The count alone is unactionable in a portfolio, because
            // each property is judged on its own latest month — so "your latest payroll month" is
            // several different months at once, and the operator cannot tell which run to correct.
            $names = Asset::query()
                ->whereIn('id', array_filter(array_column($offending, 'asset_id')))
                ->pluck('name', 'id');

            $detail = collect($offending)
                ->map(fn (array $row): string => sprintf(
                    '%s · %s',
                    $row['asset_id'] === null
                        ? __('admin.fields.portfolio')
                        : ($names[$row['asset_id']] ?? '#'.$row['asset_id']),
                    CarbonImmutable::parse($row['period'])->format('m/Y'),
                ))
                ->join('، ');

            return self::check(
                key: 'payroll_rates_configured',
                category: self::PAYROLL,
                severity: self::BLOCKING,
                ok: false,
                detail: $detail,
                count: array_sum(array_column($offending, 'runs')),
            );
        }

        // Judged on the rung in force for the CURRENT month, not on a flat setting (EG-03). The
        // question this row asks — "is payroll configured?" — is a question about now, and the
        // ladder can legitimately be nil for an old period and set for this one.
        $allNil = PayrollRates::for()->isNil();

        // A property with a live roster and no approved month yet — judged per property for the same
        // reason the blocking branch is, or one mall that has been running payroll for a year
        // silences the advisory for the mall onboarded last week.
        // ANY property still awaiting its first run — the right quantifier for the VERDICT, because
        // one mall running payroll for a year must not silence the advisory for the mall onboarded
        // last week.
        $awaitingFirstRun = $rosterProperties->diff($latestPerProperty->pluck('asset_id'))->isNotEmpty();

        // …but the WRONG quantifier for the sentence. "No payroll has been approved yet" is false
        // for every mall that has been running one, and in a mixed portfolio that is most of them —
        // the same empty claim, inverted, that this check was rewritten to stop making. The sentence
        // is chosen on whether ANYTHING has been approved anywhere.
        $nothingApprovedAnywhere = $latestPerProperty->isEmpty();

        // Each state says what it FOUND. A green row reading "your latest payroll month carries its
        // deductions" on an install that has never run payroll is the same empty claim this check
        // was rewritten to stop making.
        return self::check(
            key: 'payroll_rates_configured',
            category: self::PAYROLL,
            severity: self::ADVISORY,
            ok: ! ($allNil && $awaitingFirstRun),
            detail: __($nothingApprovedAnywhere
                ? 'admin.config_health.payroll_awaiting_first_run'
                : 'admin.config_health.payroll_ok'),
        );
    }

    /**
     * A tax invoice without the seller's registration number is not one.
     *
     * Under the VAT Law 67/2016 executive regulations the invoice must name the supplier and their
     * registration number, and **a tenant cannot support an input-VAT deduction without it** — so
     * this is the operator's compliance problem arriving as their tenants' complaint. The PDF prints
     * the line only when it is set, which makes an unconfigured install silently incomplete rather
     * than confidently wrong; this is what stops "silently" meaning "unnoticed".
     *
     * **Two documents depend on it, not one** (2026-08-17): the credit note carries the same
     * particulars, because it is what the tenant uses to REVERSE input tax they already claimed.
     * Its sibling setting `seller_legal_name` is not blocking but is not cosmetic either — it is the
     * name every generated document leads with (`App\Support\IssuingEntity`), including the hosted
     * payment page a cardholder reads before entering their card details. Unset, all of them fall
     * back to "Atriom", which is the software.
     */
    private static function sellerTaxIdentity(): array
    {
        $trn = trim((string) app(TaxSettings::class)->seller_tax_registration_number);

        return self::check(
            key: 'seller_tax_identity',
            category: self::TAX,
            severity: self::BLOCKING,
            ok: $trn !== '',
            detail: $trn !== '' ? $trn : '',
        );
    }

    /**
     * Nobody can ask about a bill.
     *
     * Advisory rather than blocking: an invoice with no contact line is still a valid invoice, and
     * every other particular on it is right. It is here because EG-05 turned a WRONG contact into
     * NO contact — the documents used to print `billing@{property-slug}.test`, which reached nobody
     * — and the honest version of that fix is silence plus a row telling the operator to fill it,
     * not silence alone.
     */
    private static function billingContact(): array
    {
        $email = IssuingEntity::billingEmail();

        return self::check(
            key: 'billing_contact',
            category: self::BILLING,
            severity: self::ADVISORY,
            ok: $email !== '',
            detail: $email,
        );
    }

    /**
     * A charge code nobody has classified bills through the FLOOR, not the accountant's ruling.
     *
     * `Vat::rateForType()` falls back to `EXEMPT_TYPES` and then to the standard rate for a code
     * with no `tax_code`. That is the correct safety net for an unseeded database and the wrong
     * answer for a live one: a code the accountant has not ruled on is charged 14% by assumption,
     * on every invoice, until somebody notices.
     */
    private static function chargeCodesClassified(): array
    {
        $unclassified = ChargeCode::query()
            ->where('is_active', true)
            ->whereNull('tax_code')
            ->pluck('code')
            ->all();

        return self::check(
            key: 'charge_codes_classified',
            category: self::TAX,
            severity: self::BLOCKING,
            ok: $unclassified === [],
            detail: implode(', ', $unclassified),
            count: count($unclassified),
        );
    }

    /**
     * Tax codes the operator's own sheet lists but nobody can use yet.
     *
     * Stamp and schedule tax ship switched off because their GL accounts are not wired — deliberate,
     * and inert by design. It is still worth saying out loud: an accountant who goes looking for
     * "Schedule 8%" and cannot find it should learn why here rather than concluding the catalogue
     * is incomplete.
     */
    private static function taxCodesCommissioned(): array
    {
        $waiting = TaxCode::query()
            ->where('is_active', false)
            ->where('treatment', TaxCode::STANDARD)
            ->pluck('code')
            ->all();

        return self::check(
            key: 'tax_codes_commissioned',
            category: self::TAX,
            severity: self::ADVISORY,
            ok: $waiting === [],
            detail: implode(', ', array_slice($waiting, 0, 6)).(count($waiting) > 6 ? '…' : ''),
            count: count($waiting),
        );
    }

    /**
     * Withholding switched on while nothing says what to withhold.
     *
     * The feature ships off. Switched ON with no default code and no supplier carrying one, every
     * payment withholds nothing — which looks exactly like it working, and leaves the operator
     * liable for the tax they failed to deduct.
     */
    private static function withholdingConfigured(): array
    {
        $settings = app(TaxSettings::class);

        if (! $settings->wht_enabled) {
            return self::check('withholding_configured', self::TAX, self::ADVISORY, true, __('admin.config_health.wht_off'));
        }

        $hasDefault = trim((string) $settings->wht_default_tax_code) !== '';
        $withCode = Vendor::query()->whereNotNull('withholding_tax_code')->count();

        return self::check(
            key: 'withholding_configured',
            category: self::TAX,
            severity: self::BLOCKING,
            ok: $hasDefault || $withCode > 0,
            detail: $hasDefault ? $settings->wht_default_tax_code : '',
            count: $withCode,
        );
    }

    /**
     * A posting role with no account books nowhere.
     *
     * `App\Support\Health` already asks this and it is repeated here on purpose: an operator working
     * through configuration should not have to know that half the answer lives in a console command
     * they have never run.
     */
    private static function postingMapComplete(): array
    {
        $mapped = LedgerAccount::query()->where('is_postable', true)->count();
        $readiness = Health::accountingReadiness();

        return self::check(
            key: 'posting_map_complete',
            category: self::ACCOUNTING,
            severity: self::BLOCKING,
            ok: (bool) ($readiness['ok'] ?? false),
            detail: (string) ($readiness['detail'] ?? ''),
            count: $mapped,
        );
    }

    /**
     * Today has to land in an open period, or nothing posts.
     *
     * A MISSING period is allowed by the posting-date guard and a CLOSED one is refused — so a
     * calendar that has not been extended into the current month does not fail loudly, it simply
     * stops accepting the entries the guard protects. This is where that becomes visible.
     */
    private static function openAccountingPeriod(): array
    {
        $today = CarbonImmutable::now()->toDateString();

        $period = AccountingPeriod::query()
            ->whereDate('starts_on', '<=', $today)
            ->whereDate('ends_on', '>=', $today)
            ->first();

        return self::check(
            key: 'open_accounting_period',
            category: self::ACCOUNTING,
            severity: self::BLOCKING,
            ok: $period !== null && $period->status !== 'closed',
            detail: $period?->status ?? '',
        );
    }

    /** @return array{key: string, category: string, severity: string, ok: bool, detail: string, count: int} */
    private static function check(string $key, string $category, string $severity, bool $ok, string $detail = '', int $count = 0): array
    {
        return compact('key', 'category', 'severity', 'ok', 'detail', 'count');
    }
}
