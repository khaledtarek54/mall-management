<?php

namespace Database\Seeders;

use App\Models\TaxCode;
use App\Models\TaxRate;
use Illuminate\Database\Seeder;

/**
 * The Egyptian tax catalogue — **the operator's own sheet**, seeded on install and safe to re-run.
 *
 * ## This is the client's data, not our reading of Egyptian tax law
 *
 * Every row here comes from the `account.tax` sheet the operator supplied on 2026-07-19, captured
 * verbatim in [docs/accounting/EGYPTIAN-TAX-CATALOG.md](../../docs/accounting/EGYPTIAN-TAX-CATALOG.md).
 * That document carries the instruction this seeder exists to obey — *"Do not invent rows beyond
 * this sheet — this is the client's actual data"* — and it is worth stating why the instruction is
 * right rather than merely following it: an invented tax code is indistinguishable, on screen, from
 * one the operator's accountant asked for. It would be picked, billed, and filed.
 *
 * So the shape here follows the sheet and not a tidier idea of it. In particular the sheet
 * organises schedule and withholding taxes **by rate** — "8% S", "1% WH" — rather than by the
 * nature of the supply. That is the Odoo model the operator is used to, where a supply is pointed
 * at whichever rate the accountant rules applies.
 *
 * ## Four families, two directions
 *
 *   - **VAT** (ضريبة القيمة المضافة) — 14%, zero-rated, exempt.
 *   - **Stamp** (ضريبة الدمغة) — 20%.
 *   - **Schedule** (ضريبة الجدول) — 0.5 · 1 · 5 · 8 · 10 · 15 · 30%.
 *   - **Withholding** (خصم وتحصيل تحت حساب الضريبة) — 0.5 · 1 · 3 · 5%, stored **negative**
 *     because the tax is deducted from what is paid rather than added to it.
 *
 * Every one exists in both directions — `sales` (what the operator charges) and `purchases` (what
 * a supplier charges the operator) — because that is how the sheet lists them and because the two
 * sides post to different accounts and land on opposite sides of the return.
 *
 * ## What ships switched on, and why the rest does not
 *
 * A code is activated only when it can actually bill, and what that takes depends on the treatment:
 *
 *   - **Exempt and zero-rated** collect nothing, so they need neither a rate nor an account and are
 *     usable the moment they exist. Base rent is billed under one.
 *   - **Standard-rated** needs a rate (all of these have one — the operator supplied them) **and** a
 *     GL account for its family. VAT has both: `vat_payable` and `vat_recoverable` are registered
 *     posting roles pointing at real accounts. **Stamp and schedule tax do not yet** — their
 *     accounts are the "GL wiring (later)" line in the catalogue document — so they seed inactive,
 *     and `TaxCode`'s own guard is what commissioning them has to satisfy (roadmap TX-08).
 *
 * Keying that on the treatment rather than on "has a posting role" fixed a real defect: it made
 * activation an accident of which LAYER created the row. The sales-side exempt and zero-rated codes
 * are created by the 120100 migration (which writes them active) and their purchases twins by this
 * seeder (which did not), so `VAT_EXEMPT` was offered on an invoice while `VAT_EXEMPT_P` was missing
 * from every purchase form.
 *
 * Withholding has its account (`withholding_tax_payable`), and since 2026-08-12 the vendor-payment
 * path resolves its rate from these codes — a supplier names one, or the portfolio default does.
 * They seed active
 * because the catalogue is the operator's reference as much as the engine's; they stay out of every
 * document picker by FAMILY ({@see TaxCode::SUPPLY_FAMILIES}), not by being switched off, because
 * withholding is not a tax on a supply at all.
 *
 * ## Idempotent, and it never overrules the accountant
 *
 * Labels, family, direction and posting role are re-asserted on every run. **Treatment, activation
 * and rates are written on first creation only**: a re-seed that reset a rate the accountant had
 * moved, or switched a code back off after they had commissioned it, would change what the next
 * invoice charges with nobody looking at a seeder when it happened. Same rule, and same reason, as
 * `ChargeCodeSeeder`.
 *
 * ## Egyptian taxes deliberately NOT in this catalogue
 *
 * This table holds taxes applied *to a document line at a rate*, which is what the operator's sheet
 * describes. Three Egyptian taxes are real obligations of this operator and are **not** that shape,
 * so putting them here would model them wrongly rather than completely:
 *
 *   - **Real estate tax — الضريبة العقارية (Law 196/2008).** Assessed on the property's rental
 *     value by the tax authority, not computed from a document. It is an operating cost, and it is
 *     already handled where it belongs: as its own CAM expense pool (so it is recovered from
 *     tenants under the lease) and as an expense in the GL.
 *   - **Corporate income tax — ضريبة الأرباح (Law 91/2005).** Levied on annual profit at the
 *     entity level. It has no per-document rate; it is computed from the financial statements after
 *     the year is closed.
 *   - **Salary tax and social insurance — ضريبة كسب العمل والتأمينات الاجتماعية.** Per-employee and
 *     on a progressive bracket table, not a single rate on a line. They live in `PayrollSettings`
 *     and module 24 (HR/payroll), which is where a bracket table can be expressed.
 *
 * @see TaxCode
 */
class TaxCodeSeeder extends Seeder
{
    /**
     * The day the current VAT regime began — VAT Law 67/2016 moved the standard rate to 14% on
     * 1 July 2017. Used as the opening rung for every code.
     *
     * The operator's sheet carries rates but no effective dates, and for most of these codes a date
     * is not a meaningful property anyway: a tax NAMED "8% S" is 8%, and a schedule change means the
     * accountant points the supply at a different code. Dating them all from the start of the
     * current regime is the reading that makes every document this system can hold resolvable, and
     * the ladder is there for the one code where the date does matter — VAT, when it next moves.
     */
    public const CATALOGUE_EPOCH = '2017-07-01';

    /** The standard VAT rate in force since {@see CATALOGUE_EPOCH}. */
    public const VAT_STANDARD_RATE = 14.0;

    /** @deprecated Kept as the name the VAT rung is dated by; identical to {@see CATALOGUE_EPOCH}. */
    public const VAT_STANDARD_FROM = self::CATALOGUE_EPOCH;

    private const VAT_LAW = 'VAT Law 67/2016 — ضريبة القيمة المضافة';

    private const SCHEDULE_LAW = 'VAT Law 67/2016, Schedule — ضريبة الجدول';

    private const STAMP_LAW = 'Stamp Duty Law 111/1980 — ضريبة الدمغة';

    private const WHT_LAW = 'Income Tax Law 91/2005 — خصم وتحصيل تحت حساب الضريبة';

    /** Schedule-tax rates, from the operator's sheet. */
    private const SCHEDULE_RATES = [0.5, 1, 5, 8, 10, 15, 30];

    /** Withholding rates, from the operator's sheet. Applied negative — deducted, not added. */
    private const WITHHOLDING_RATES = [0.5, 1, 3, 5];

    public function run(): void
    {
        foreach ($this->catalogue() as $row) {
            $this->upsert($row);
        }

        TaxCode::flushLookupCaches();
    }

    /**
     * The sheet, expanded across both directions.
     *
     * @return array<int, array{code: string, en: string, ar: string, family: string, direction: string, treatment: string, role: ?string, label: string, ref: string, sort: int, rate: ?float}>
     */
    private function catalogue(): array
    {
        $rows = [];

        foreach ([TaxCode::SALES, TaxCode::PURCHASES] as $direction) {
            $sales = $direction === TaxCode::SALES;
            $suffix = $sales ? '' : '_P';
            $base = $sales ? 0 : 1000;
            $way = $sales ? 'sales' : 'purchases';

            // ── VAT ──────────────────────────────────────────────────────────────────────────
            $rows[] = [
                'code' => 'VAT_14'.$suffix,
                'en' => $sales ? 'VAT 14%' : 'Input VAT 14%',
                'ar' => 'ضريبة القيمة المضافة ١٤٪'.($sales ? '' : ' — مشتريات'),
                'family' => TaxCode::FAMILY_VAT, 'direction' => $direction,
                'treatment' => TaxCode::STANDARD,
                'role' => $sales ? 'vat_payable' : 'vat_recoverable',
                'label' => 'VAT 14%', 'ref' => self::VAT_LAW,
                'sort' => $base + 10, 'rate' => self::VAT_STANDARD_RATE,
            ];
            $rows[] = [
                'code' => 'VAT_0'.$suffix,
                'en' => 'Zero Rated 0%', 'ar' => 'خاضعة بنسبة صفر',
                'family' => TaxCode::FAMILY_VAT, 'direction' => $direction,
                'treatment' => TaxCode::ZERO_RATED, 'role' => null,
                'label' => 'Zero Rated 0%', 'ref' => self::VAT_LAW.' — zero-rated supplies',
                'sort' => $base + 20, 'rate' => 0.0,
            ];
            // Exempt is a separate code from zero-rated even though both bill 0: they are different
            // lines on the return, and the distinction cannot be recovered later from a document
            // that recorded nothing but a zero. The operator's sheet lists them separately too.
            $rows[] = [
                'code' => 'VAT_EXEMPT'.$suffix,
                'en' => 'Exempt', 'ar' => 'معفاة',
                'family' => TaxCode::FAMILY_VAT, 'direction' => $direction,
                'treatment' => TaxCode::EXEMPT, 'role' => null,
                'label' => 'Exempt', 'ref' => self::VAT_LAW.' — exempt supplies',
                'sort' => $base + 30, 'rate' => 0.0,
            ];

            // ── Stamp — ضريبة الدمغة ─────────────────────────────────────────────────────────
            $rows[] = [
                'code' => 'STAMP_20'.$suffix,
                'en' => 'Stamp 20%', 'ar' => 'ضريبة الدمغة ٢٠٪',
                'family' => TaxCode::FAMILY_STAMP, 'direction' => $direction,
                'treatment' => TaxCode::STANDARD,
                // Output = a liability we collect and remit. Input = an EXPENSE, not a recoverable
                // asset: stamp duty has no credit mechanism, so mirroring VAT here would build a
                // receivable that can never be realised. See ChartOfAccountsSeeder 51111.
                'role' => $sales ? 'stamp_tax_payable' : 'stamp_tax_expense',
                'label' => 'Stamp', 'ref' => self::STAMP_LAW,
                'sort' => $base + 100, 'rate' => 20.0,
            ];

            // ── Schedule — ضريبة الجدول ──────────────────────────────────────────────────────
            foreach (self::SCHEDULE_RATES as $i => $rate) {
                $rows[] = [
                    'code' => 'SCHD_'.self::slug($rate).$suffix,
                    'en' => 'Schedule '.self::pct($rate),
                    'ar' => 'ضريبة الجدول '.self::pct($rate),
                    'family' => TaxCode::FAMILY_SCHEDULE, 'direction' => $direction,
                    'treatment' => TaxCode::STANDARD,
                    // Same asymmetry as stamp above, for the same reason.
                    'role' => $sales ? 'schedule_tax_payable' : 'schedule_tax_expense',
                    'label' => 'SCHD '.self::pct($rate),
                    'ref' => self::SCHEDULE_LAW,
                    'sort' => $base + 200 + $i, 'rate' => (float) $rate,
                ];
            }

            // ── Withholding — negative, because it is deducted at source ─────────────────────
            foreach (self::WITHHOLDING_RATES as $i => $rate) {
                $rows[] = [
                    'code' => 'WH_'.self::slug($rate).$suffix,
                    'en' => 'Withholding -'.self::pct($rate),
                    'ar' => 'خصم وتحصيل تحت حساب الضريبة '.self::pct($rate),
                    'family' => TaxCode::FAMILY_WITHHOLDING, 'direction' => $direction,
                    'treatment' => TaxCode::STANDARD, 'role' => 'withholding_tax_payable',
                    'label' => 'WH -'.self::pct($rate),
                    'ref' => self::WHT_LAW.' — '.$way,
                    // The sign is the CATALOGUE's, and it has one home — the form that lets an
                    // accountant add the next rung reads the same rule back as its bounds.
                    'sort' => $base + 300 + $i,
                    'rate' => TaxCode::signedRate((float) $rate, TaxCode::FAMILY_WITHHOLDING),
                ];
            }
        }

        return $rows;
    }

    /** @param array{code: string, en: string, ar: string, family: string, direction: string, treatment: string, role: ?string, label: string, ref: string, sort: int, rate: ?float} $row */
    private function upsert(array $row): void
    {
        $code = TaxCode::firstOrNew(['code' => $row['code']]);
        $isNew = ! $code->exists;

        if ($isNew) {
            // The accountant's ruling, set on create and never re-asserted. Activation waits until
            // the rung below exists — a rung needs the code's id, and `TaxCode` refuses to be
            // switched on while it could not bill.
            $code->treatment = $row['treatment'];
            $code->is_active = false;
        }

        $code->fill([
            'name_en' => $row['en'],
            'name_ar' => $row['ar'],
            'family' => $row['family'],
            'direction' => $row['direction'],
            'posting_role' => $row['role'],
            'invoice_label' => $row['label'],
            'statutory_reference' => $row['ref'],
            'sort_order' => $row['sort'],
        ])->save();

        if ($row['rate'] !== null && $code->rates()->doesntExist()) {
            TaxRate::create([
                'tax_code_id' => $code->id,
                'rate' => $row['rate'],
                'effective_from' => self::CATALOGUE_EPOCH,
                'note' => $row['ref'],
            ]);
        }

        // Only a code that can bill, and only on the run that created it — a reseed must not
        // reactivate one the operator deliberately retired.
        //
        // "Can bill" turns on the TREATMENT. An exempt or zero-rated code collects nothing, so it
        // needs neither a rate nor an account and is usable the moment it exists — base rent is
        // billed under one. A standard-rated code needs both.
        //
        // **Stamp and schedule tax now have both** (2026-08-19), so a FRESH install ships them on.
        // That took more than adding accounts: until the same change both journalizers threw the
        // document's own `tax_code` away and credited every tax to `vat_payable`, so an active stamp
        // code would have put 20% on the VAT return. An EXISTING database gets the posting role
        // backfilled by the `fill()` above and stays switched OFF — the operator turns it on at
        // `/admin/tax-codes`, which is the accountant's act, and the rule below about never
        // reactivating a retired code is worth more than saving them the click.
        //
        // Keyed on the treatment rather than on `role !== null` because that earlier test made
        // activation an accident of which LAYER created the row: the sales-side exempt and
        // zero-rated codes are created by the 120100 migration (which writes them active) and their
        // purchases twins by this seeder (which did not), so `VAT_EXEMPT` was offered on an invoice
        // while `VAT_EXEMPT_P` was missing from every purchase form.
        $canBill = $row['treatment'] !== TaxCode::STANDARD
            || ($row['rate'] !== null && $row['role'] !== null);

        if ($isNew && $canBill) {
            $code->update(['is_active' => true]);
        }
    }

    /** 0.5 → "0_5", 8 → "8" — a code fragment, since a dot is not one. */
    private static function slug(float|int $rate): string
    {
        return str_replace('.', '_', rtrim(rtrim(number_format((float) $rate, 2, '.', ''), '0'), '.'));
    }

    /** 0.5 → "0.5%", 8 → "8%" — as the operator's sheet writes it. */
    private static function pct(float|int $rate): string
    {
        return rtrim(rtrim(number_format((float) $rate, 2, '.', ''), '0'), '.').'%';
    }
}
