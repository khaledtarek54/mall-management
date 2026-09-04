<?php

namespace App\Filament\Imports;

use App\Models\LedgerAccount;
use App\Support\CashFlowSection;
use App\Support\DataTransferNotice;
use App\Support\StatementSection;
use App\Support\ValueSets;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Load the operator's own chart of accounts (EG-28).
 *
 * The one importer this system was missing that a first deploy actually needs. Atriom ships a chart
 * so a box can post on day one, but the operator's accountant has theirs — and until now adopting
 * it meant typing a few hundred accounts into a form, which is how a chart acquires the typos that
 * misfile revenue for a year.
 *
 * ## What is deliberately NOT a column
 *
 * - **`parent_id`** — the tree is DERIVED from the code (`LedgerAccount::saving`), so a parent
 *   column would be a second, conflicting truth. See the note on ordering below.
 * - **`normal_balance`** — derived from `type` in the same hook, and the model's own docblock says
 *   it "is never set by hand". Accepting it would let a file state that an asset is credit-normal
 *   and have the system quietly disagree.
 *
 * ## Row order does not matter
 *
 * `resolveParentIdFromCode()` looks BACKWARD for an existing parent, which is complete only when
 * parents precede children — true of the seeder, which sorts by code, and false of a CSV in
 * whatever order the accountant's system exported. Filament streams rows in file order and gives no
 * after-import hook, so `LedgerAccount::adoptOrphanedDescendants()` closes the reverse direction on
 * `saved`. A file listing `11101` before `111` still ends up with the right tree.
 *
 * ## Identity is the CODE
 *
 * The same key `ChartOfAccountsSeeder` uses, so re-running an import corrects rows instead of
 * duplicating them — and so a re-import after `atriom:install` merges with the shipped chart rather
 * than fighting it. That is also the known hazard the seeder carries: renumbering an account creates
 * a second one rather than moving it, which is why the code is treated as identity and not as data.
 */
class LedgerAccountImporter extends Importer
{
    protected static ?string $model = LedgerAccount::class;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('code')
                ->label(__('admin.fields.account_code'))
                ->requiredMapping()
                // Identity. Length is not constrained to the shipped chart's width on purpose —
                // `docs/accounting/` records the 8-vs-10-digit question as still open with the
                // accountant, and the system is deliberately width-agnostic.
                ->rules(['required', 'max:32', 'regex:/^\d+$/']),

            ImportColumn::make('name_en')
                ->label(__('admin.fields.account_name_en'))
                ->requiredMapping()
                ->rules(['required', 'max:255']),

            ImportColumn::make('name_ar')
                ->label(__('admin.fields.account_name_ar'))
                ->requiredMapping()
                // Required, like its English twin: a chart half-named is one that reads as blanks
                // on every Arabic statement, and the operator would find out at the year end.
                ->rules(['required', 'max:255']),

            ImportColumn::make('type')
                ->label(__('admin.fields.account_type'))
                ->requiredMapping()
                // Validated against the column's own set AND against the coding convention, so a
                // row fails with a reason on it rather than reaching the model's exception — which
                // Filament would report as a failed row with no explanation an accountant can act
                // on.
                ->rules(['required', Rule::in(ValueSets::allowed('ledger_accounts', 'type'))]),

            ImportColumn::make('cash_flow_section')
                ->label(__('admin.fields.cash_flow_section'))
                // Optional, and the reason it exists at all: a chart arriving from another system
                // is exactly when the cash-flow classification has to be stated rather than
                // inferred from how somebody numbered it (EG-28). Blank leaves the account on the
                // operating floor, and the chart screen's "Not classified" filter finds it.
                ->rules(['nullable', Rule::in(CashFlowSection::SECTIONS)]),

            ImportColumn::make('statement_section')
                ->label(__('admin.fields.statement_section'))
                // Optional for the same reason, and it matters more: blank leaves the account ABOVE
                // the net-operating-income line, so an unstated financing cost quietly reduces the
                // figure a valuation is built on. The chart screen's "Not classified" filter finds
                // them, and net profit is right either way.
                ->rules(['nullable', Rule::in(StatementSection::SECTIONS)]),

            ImportColumn::make('is_postable')
                ->label(__('admin.fields.is_postable'))
                ->boolean()
                ->rules(['nullable', 'boolean']),

            ImportColumn::make('is_active')
                ->label(__('admin.fields.is_active'))
                ->boolean()
                ->rules(['nullable', 'boolean']),
        ];
    }

    /**
     * Match on CODE, the same identity the seeder uses.
     *
     * So a second pass corrects the first rather than duplicating it, and an import over the
     * shipped chart updates the accounts it shares instead of creating twins.
     */
    public function resolveRecord(): ?LedgerAccount
    {
        $code = trim((string) ($this->data['code'] ?? ''));

        if ($code === '') {
            return null;
        }

        // The coding convention, checked HERE rather than as a column rule: it is a rule about the
        // code AND the type together, and `getColumns()` is static so a per-column closure cannot
        // see the other cell. The model throws for the same reason — but an `InvalidArgumentException`
        // reaches the operator as a failed row with a developer's sentence on it, and this reaches
        // them as the message the form shows.
        $type = trim((string) ($this->data['type'] ?? ''));
        $expected = LedgerAccount::expectedTypeForCode($code);

        if ($expected !== null && $type !== '' && $type !== $expected) {
            throw ValidationException::withMessages([
                'type' => __('admin.validation.account_code_type_mismatch', [
                    'digit' => substr($code, 0, 1),
                    'expected' => __("admin.enums.ledger_account_type.{$expected}"),
                ]),
            ]);
        }

        return LedgerAccount::firstOrNew(['code' => $code]);
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        return DataTransferNotice::forImport($import);
    }

    /** Queued in production, `sync` locally and in the suite — same as its siblings. */
    public function getJobConnection(): ?string
    {
        return config('imports.connection', 'sync');
    }

    /** A guard rail against a mis-mapped file, not a capacity limit. */
    public function getMaxRows(): ?int
    {
        return (int) config('imports.max_rows', 5000);
    }
}
