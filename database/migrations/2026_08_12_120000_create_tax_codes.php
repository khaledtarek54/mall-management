<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tax catalogue — every rate this system may apply, and the date each came into force.
 *
 * **What this replaces.** The standard VAT rate was a single number in an application setting
 * (`TaxSettings::vat_standard_rate`) and withholding was a second single number
 * (`wht_default_rate`). Both are the wrong shape for the same two reasons:
 *
 *   1. **A rate has a date.** Egypt moved VAT 10% → 14% in 2017 (VAT Law 67/2016). With one
 *      settings field, editing it re-rates everything originated afterwards — *including* a
 *      document back-dated into the old regime, which is the one case that must not follow the new
 *      rate. A setting cannot express "14% until 31 Dec, 15% from 1 Jan"; a dated row can, and the
 *      accountant can enter it in advance.
 *   2. **One rate cannot describe a tax system.** `wht_default_rate` was a single number, while the
 *      operator's own catalogue lists withholding at four rates and schedule tax at seven, each
 *      applying to different supplies and each existing in both directions. A single default is
 *      either wrong or switched off, which is why it shipped switched off.
 *
 * **Why master data and not a bigger settings screen.** A tax rate carries a validity period and a
 * GL account, and documents reference it. That is the definition of master data, and it is the tier
 * `ledger_accounts` and `charge_codes` already live in. Settings hold *policy* ("do we withhold at
 * all"); this table holds *rates*. Every reference system splits it here — Odoo's `account.tax`
 * records, SAP's tax codes with validity periods, NetSuite's effective-dated tax codes, Yardi's
 * tax-rate tables (its `Tax` flag on the charge code is the taxability half, which
 * `charge_codes` already matches).
 *
 * ## Two tables, because identity and rate have different lifetimes
 *
 * `tax_codes` is the stable identity a charge code points at and a document records. `tax_rates` is
 * its rate over time. Collapsing them would mean a rate change either edits history or creates a
 * second code that everything pointing at the first one never learns about.
 *
 * ## No `effective_to`, deliberately
 *
 * A rate is in force from its `effective_from` until the next row for the same code starts. The
 * obvious alternative — a from/to pair per row — makes two data errors representable that this
 * shape cannot express at all: **overlapping** windows (two rates in force on one day, so which one
 * bills is whichever the query happened to order first) and **gaps** (a date with no rate, which
 * silently falls through to whatever the fallback is).
 *
 * That is not a hypothetical. It is exactly the defect `atriom:audit-charge-schedules` exists to
 * find: legacy leases whose charge rows overlap **bill nothing**, because the refusal is caught
 * rather than thrown. Having been bitten once by from/to ranges on money, this table does not offer
 * the shape. Closing a code is `is_active = false`, which stops it being offered without
 * invalidating the documents that already carry it.
 *
 * ## Family and direction, which are two different questions
 *
 * The catalogue this implements is **the operator's own** — supplied 2026-07-19 as an
 * `account.tax` sheet and captured verbatim in
 * [docs/accounting/EGYPTIAN-TAX-CATALOG.md](../../docs/accounting/EGYPTIAN-TAX-CATALOG.md). It has
 * two independent axes, and collapsing them into one would lose data the operator gave us:
 *
 *   - **`family`** — WHICH tax: `vat` · `stamp` (ضريبة الدمغة) · `schedule` (ضريبة الجدول) ·
 *     `withholding` (خصم وتحصيل تحت حساب الضريبة). This drives the GL account and the sign.
 *   - **`direction`** — `sales` (output tax the operator charges) or `purchases` (input tax the
 *     operator pays). Every rate on the sheet exists in both.
 *
 * They share a table because they share everything that matters — a dated rate, a treatment, a GL
 * account, an operator who maintains them — and because the VAT return needs both sides resolved
 * the same way. `posting_role` names the account through the existing `account_mappings`
 * indirection (`vat_payable` / `vat_recoverable` / `withholding_tax_payable` are registered roles),
 * so a code inherits per-property overrides for free.
 *
 * **Withholding rates are stored NEGATIVE**, as the operator's sheet writes them: the tax is
 * deducted from what is paid rather than added to it, and the sign is what says so.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_codes', function (Blueprint $table) {
            $table->id();

            // The stable identity. Referenced BY STRING from `charge_codes`, the same way
            // `invoice_items.type` references a charge code — an id would be a second identifier
            // for a thing that already has one, and it would make a seeded catalogue's ids part of
            // the contract between environments.
            $table->string('code', 32)->unique();

            $table->string('name_en');
            $table->string('name_ar');

            // vat | stamp | schedule | withholding — WHICH Egyptian tax this is. Drives the GL
            // account and the sign. Strings, not DB enums: which taxes exist is a jurisdiction's
            // question, and adding one must not be a migration.
            $table->string('family', 16)->default('vat');

            // sales | purchases — the operator's sheet calls this "Tax Type". Every rate exists in
            // both directions: output tax the operator charges, input tax the operator pays. They
            // are separate rows because they post to different accounts and appear on opposite
            // sides of the return, which is also how Odoo's `account.tax` models it.
            $table->string('direction', 16)->default('sales');

            // standard | exempt | zero_rated — the same vocabulary `charge_codes.vat_treatment`
            // used, moved up a level so it is stated once per TAX rather than once per charge code.
            // Exempt and zero-rated both bill 0 and differ on the return, which is the whole reason
            // the distinction is stored rather than inferred from a zero on a line.
            $table->string('treatment', 16)->default('standard');

            // The GL role this tax lands in, resolved through `account_mappings`. Null for exempt /
            // zero-rated codes, which post nothing because they collect nothing.
            $table->string('posting_role')->nullable();

            // What prints on the invoice — "VAT 14%", "SCHD 8%", "WH -1%". The operator's sheet
            // carries it as its own column, and it is not derivable from the name: the same 8%
            // schedule tax is labelled "SCHD 8%" whichever direction it runs in.
            $table->string('invoice_label');

            // The statute this tax comes from — "VAT Law 67/2016", "Stamp Duty Law 111/1980".
            // Carried on the CODE and not only on the rate rung because the question an auditor asks
            // about a tax rate is never "what" but "on what authority", and because a code may
            // legitimately ship with no rung at all — then this is what says which law to open.
            $table->string('statutory_reference')->nullable();

            // Ships FALSE for any code that cannot yet bill — no rate, or no GL account wired for
            // its family. An active code with an empty ladder would appear in the picker and resolve
            // to no rate at all, so activation is a deliberate signal that it is commissioned.
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['family', 'direction', 'is_active']);
        });

        Schema::create('tax_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tax_code_id')->constrained()->cascadeOnDelete();

            // 6,3 rather than 5,2: withholding rates in Egypt run to half a percent (0.5% on
            // supplies), and a rate that cannot express the statutory figure is worse than no
            // configuration at all.
            $table->decimal('rate', 6, 3);

            $table->date('effective_from');

            // What the accountant was reading when they entered it — "VAT Law 67/2016 art. 2".
            // The audit question about a tax rate is never "what" but "on what authority".
            $table->string('note')->nullable();

            $table->timestamps();

            // One rate per code per day. The unique index is what makes "the latest row on or
            // before this date" a single, deterministic answer.
            $table->unique(['tax_code_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_rates');
        Schema::dropIfExists('tax_codes');
    }
};
