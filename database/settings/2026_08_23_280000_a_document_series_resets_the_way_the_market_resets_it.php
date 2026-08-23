<?php

use App\Support\DocumentNumbering;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * EG-10 — when a document series starts counting again.
 *
 * Atriom shipped a MONTHLY reset (`INV-AW-202608-0417`). That is a convention nobody chose, and no
 * major system uses it: SAP, Oracle, NetSuite and Odoo reset accounting document numbers per YEAR,
 * while Yardi and MRI use continuous control numbers that never reset. Twelve series per mall per
 * year is the kind of thing an auditor asks about, and the answer has to be "we chose that".
 *
 * ## An install that has already issued documents keeps its series
 *
 * Numbers are allocated as `MAX(number)` within a prefix, so a new scheme means a new prefix shape
 * and a fresh sequence starting at 1 — the old documents keep their numbers and the type now has
 * two series. Harmless on an empty install and exactly the discontinuity an auditor would query on
 * a live one.
 *
 * So this reads the books before it decides: **any invoice at all and the install stays MONTHLY**,
 * which is precisely what it has been doing. A fresh install gets the market default. Either way
 * nothing already numbered changes, and the operator can move deliberately from the settings screen
 * while the go-live window is still open.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add(
            'accounting.document_number_reset',
            $this->hasIssuedDocuments() ? DocumentNumbering::MONTHLY : DocumentNumbering::DEFAULT_RESET,
        );
    }

    /**
     * Has this install numbered anything yet?
     *
     * Invoices only: they are the first document any install produces and the one whose series an
     * auditor actually follows. The `hasTable` guard is for a fresh database, where this migration
     * can run before the table exists.
     */
    private function hasIssuedDocuments(): bool
    {
        return Schema::hasTable('invoices') && DB::table('invoices')->exists();
    }
};
