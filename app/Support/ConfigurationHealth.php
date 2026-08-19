<?php

namespace App\Support;

use App\Models\AccountingPeriod;
use App\Models\ChargeCode;
use App\Models\LedgerAccount;
use App\Models\TaxCode;
use App\Models\Vendor;
use App\Settings\TaxSettings;
use Carbon\CarbonImmutable;

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

    /** @var array<int, string> */
    public const CATEGORIES = [self::TAX, self::ACCOUNTING, self::BILLING];

    /**
     * Every configuration check, in the order an operator should work through them.
     *
     * @return array<int, array{key: string, category: string, severity: string, ok: bool, detail: string}>
     */
    public static function run(): array
    {
        return [
            self::sellerTaxIdentity(),
            self::chargeCodesClassified(),
            self::taxCodesCommissioned(),
            self::withholdingConfigured(),
            self::postingMapComplete(),
            self::openAccountingPeriod(),
        ];
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
