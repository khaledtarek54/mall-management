<?php

namespace App\Filament\Admin\Resources\RecurringExpenses\Schemas;

use App\Models\BankAccount;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Models\RecurringExpense;
use App\Models\TaxCode;
use App\Models\Vendor;
use App\Models\VendorContract;
use App\Support\Filament\EntitySelect;
use App\Support\Filament\PropertyField;
use App\Support\TenantScope;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class RecurringExpenseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                // Never a bare EntitySelect on asset_id — the pinned component, per the property
                // isolation rule.
                PropertyField::make(),

                // Naming a supplier is what turns this schedule from "money leaving" into a
                // PAYABLE — `expenses` carries no vendor at all, so the presence of one IS the
                // statement. Blank keeps it an expense, which is what every existing schedule is.
                EntitySelect::make('vendor_id')
                    ->label(__('admin.fields.vendor'))
                    ->entity(Vendor::class)
                    ->live()
                    ->helperText(__('admin.recurring_expenses.help.vendor')),

                EntitySelect::make('vendor_contract_id')
                    ->label(__('admin.fields.vendor_contract'))
                    ->entity(VendorContract::class)
                    // Same narrowing as the vendor-bill form's own contract picker, including the
                    // portfolio-wide exception (`asset_id IS NULL` — a master agreement covering
                    // every mall), which the derived property scope cannot know about.
                    ->modifyOptionsQuery(function ($query, Get $get) {
                        $vendorId = $get('vendor_id');

                        if (blank($vendorId)) {
                            return $query->whereRaw('1 = 0');
                        }

                        $visible = TenantScope::visibleAssetIds();

                        return $query
                            ->where('vendor_id', $vendorId)
                            ->when($visible !== null, fn ($q) => $q->where(
                                fn ($w) => $w->whereIn('asset_id', $visible)->orWhereNull('asset_id'),
                            ));
                    })
                    ->visible(fn (Get $get): bool => filled($get('vendor_id'))),

                TextInput::make('description')
                    ->label(__('admin.fields.description'))
                    ->required()
                    ->maxLength(200)
                    ->helperText(__('admin.recurring_expenses.help.description')),

                Select::make('category')
                    ->label(__('admin.fields.category'))
                    ->options(fn () => ExpenseCategory::options())
                    ->required()
                    ->native(false)
                    ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.recurring_expenses.hints.category')),

                TextInput::make('amount')
                    ->label(__('admin.fields.amount'))
                    ->numeric()
                    ->minValue(0)
                    ->required()
                    ->prefix('EGP')
                    ->helperText(__('admin.recurring_expenses.help.amount')),

                Select::make('frequency')
                    ->label(__('admin.fields.frequency'))
                    ->options(fn (): array => collect(RecurringExpense::FREQUENCIES)
                        ->mapWithKeys(fn (string $f): array => [$f => __("admin.recurring_expenses.frequencies.{$f}")])
                        ->all())
                    ->required()
                    ->native(false),

                TextInput::make('payment_terms_days')
                    ->label(__('admin.recurring_expenses.fields.payment_terms_days'))
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(365)
                    ->suffix(__('admin.recurring_expenses.days'))
                    // Only a payable has terms. An expense is money already gone.
                    ->visible(fn (Get $get): bool => filled($get('vendor_id')))
                    ->helperText(__('admin.recurring_expenses.help.payment_terms_days')),

                TextInput::make('day_of_month')
                    ->label(__('admin.recurring_expenses.fields.day_of_month'))
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(31)
                    ->default(1)
                    ->required()
                    ->helperText(__('admin.recurring_expenses.help.day_of_month')),

                DatePicker::make('starts_on')
                    ->label(__('admin.fields.start_date'))
                    ->required()
                    ->native(false)
                    ->helperText(__('admin.recurring_expenses.help.starts_on')),

                DatePicker::make('ends_on')
                    ->label(__('admin.fields.end_date'))
                    ->native(false)
                    ->placeholder(__('admin.recurring_expenses.fields.no_end'))
                    ->helperText(__('admin.recurring_expenses.help.ends_on')),

                // **WHICH RAIL THE MONEY LEAVES BY.** The generator omitted it, so every expense it
                // minted fell to the column default and credited CASH — while the costs this screen
                // exists for (real-estate tax, municipal levies, a licence renewal, a fixed
                // retainer) all leave a bank account. Left blank the generated expense keeps the
                // old default, so nothing an install already runs changes.
                Select::make('paid_from')
                    ->label(__('admin.fields.paid_from'))
                    ->options(fn () => PaymentMethod::optionsFor('expenses.paid_from', 'admin.enums.expense_paid_from'))
                    ->native(false)
                    ->placeholder(__('admin.enums.expense_paid_from.cash'))
                    ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.recurring_expenses.hints.paid_from')),

                // The rail says WHICH KIND of account; this says which one. `MoneyAccount` resolves
                // the document's own bank account first, then the rail's mapped account, then the
                // posting role — so a mall banking in two places needs this or both banks' money
                // lands in one chart account.
                EntitySelect::make('bank_account_id')
                    ->label(__('admin.fields.bank_account_id'))
                    ->entity(BankAccount::class)
                    ->visible(fn (Get $get): bool => ($get('paid_from') ?? 'cash') !== 'cash'),

                Select::make('tax_code')
                    ->label(__('admin.fields.tax_code'))
                    ->options(fn () => TaxCode::options(TaxCode::PURCHASES))
                    ->native(false)
                    ->placeholder(__('admin.charge_codes.tax_unclassified'))
                    ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.recurring_expenses.hints.tax_code')),

                Toggle::make('is_active')
                    ->label(__('admin.fields.is_active'))
                    ->default(true)
                    ->helperText(__('admin.recurring_expenses.help.is_active')),

                // **WHERE THE SCHEDULE STANDS, ON THE SCREEN THE OPERATOR CHANGES IT FROM.** The
                // LIST has shown the next booking since EG-33; the edit form — the one place the
                // day, the frequency and the end date are actually moved — showed neither it nor
                // the last booked period, so the consequence of an edit was inferable only from a
                // helper sentence. It reads the same `nextDueOn()` the list and the nightly run
                // read, so the three cannot disagree about what happens next.
                Text::make(fn (?RecurringExpense $record): string => self::standing($record))
                    ->columnSpanFull(),

                Textarea::make('notes')
                    ->label(__('admin.fields.notes'))
                    ->rows(2)
                    ->columnSpanFull(),
            ]);
    }

    /**
     * Where the schedule stands, in one line — read from the same `nextDueOn()` the list column and
     * the nightly run read, so the three cannot disagree about what happens next.
     */
    private static function standing(?RecurringExpense $record): string
    {
        if ($record === null) {
            return __('admin.recurring_expenses.fields.next_due_on_save');
        }

        $next = $record->nextDueOn(CarbonImmutable::now()->addYears(2))?->toDateString();

        $line = $next === null
            ? __('admin.recurring_expenses.fields.nothing_due')
            : __('admin.recurring_expenses.fields.next_due_is', ['date' => $next]);

        if ($record->last_generated_on !== null) {
            $line .= ' — '.__('admin.recurring_expenses.fields.last_booked', [
                'date' => $record->last_generated_on->toDateString(),
            ]);
        }

        return $line;
    }
}
