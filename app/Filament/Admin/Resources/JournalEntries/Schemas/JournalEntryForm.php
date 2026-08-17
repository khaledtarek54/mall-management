<?php

namespace App\Filament\Admin\Resources\JournalEntries\Schemas;

use App\Models\Asset;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Support\Filament\EntitySelect;
use App\Support\TenantScope;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class JournalEntryForm
{
    public static function configure(Schema $schema): Schema
    {
        // Once posted, an entry is immutable — void it to undo. Lock the form.
        $locked = fn (?JournalEntry $record) => $record !== null && $record->status !== 'draft';

        return $schema->columns(1)->components([
            Section::make(__('admin.sections.journal_entry_details'))
                ->columns(3)
                ->components([
                    TextInput::make('number')
                        ->label(__('admin.fields.journal_number'))
                        ->disabled()
                        ->dehydrated(false)
                        ->placeholder(__('admin.fields.auto_generated')),

                    DatePicker::make('entry_date')
                        ->label(__('admin.fields.entry_date'))
                        ->required()
                        ->default(now())
                        ->native(false)
                        ->disabled($locked),

                    EntitySelect::make('asset_id')
                        ->label(__('admin.fields.property'))
                        ->entity(Asset::class)
                        ->default(fn () => TenantScope::currentAssetId())
                        ->searchable()
                        ->preload()
                        ->placeholder(__('admin.fields.property_consolidated'))
                        ->helperText(__('admin.helpers.journal_property'))
                        ->disabled($locked),

                    TextInput::make('description_ar')
                        ->label(__('admin.fields.entry_description_ar'))
                        ->maxLength(255)
                        ->columnSpan(['default' => 1, 'sm' => 3])
                        ->disabled($locked),

                    TextInput::make('description_en')
                        ->label(__('admin.fields.entry_description_en'))
                        ->maxLength(255)
                        ->columnSpan(['default' => 1, 'sm' => 3])
                        ->disabled($locked),
                ]),

            Section::make(__('admin.sections.journal_lines'))
                ->components([
                    Repeater::make('lines')
                        ->relationship()
                        ->label('')
                        ->columns(12)
                        ->defaultItems(2)
                        ->minItems(2)
                        ->addActionLabel(__('admin.actions.add_line'))
                        ->reorderable(false)
                        ->live()
                        ->disabled($locked)
                        ->schema([
                            Select::make('ledger_account_id')
                                ->label(__('admin.fields.line_account'))
                                ->options(fn () => LedgerAccount::postableOptions())
                                ->searchable()
                                ->required()
                                ->columnSpan(5),
                            TextInput::make('debit')
                                ->label(__('admin.fields.debit'))
                                ->prefix('EGP')
                                ->numeric()
                                ->minValue(0)
                                ->default(0)
                                ->live(onBlur: true)
                                ->columnSpan(3),
                            TextInput::make('credit')
                                ->label(__('admin.fields.credit'))
                                ->prefix('EGP')
                                ->numeric()
                                ->minValue(0)
                                ->default(0)
                                ->live(onBlur: true)
                                ->columnSpan(3),
                            TextInput::make('description')
                                ->label(__('admin.fields.line_description'))
                                ->maxLength(255)
                                ->columnSpan(['default' => 12, 'sm' => 1]),
                        ]),
                ]),

            Section::make(__('admin.sections.journal_totals'))
                ->columns(3)
                ->components([
                    Text::make(fn (Get $get) => self::totalLine(
                        __('admin.fields.total_debit'),
                        self::sumColumn($get, 'debit'),
                    )),
                    Text::make(fn (Get $get) => self::totalLine(
                        __('admin.fields.total_credit'),
                        self::sumColumn($get, 'credit'),
                    )),
                    Text::make(function (Get $get) {
                        $diff = round(self::sumColumn($get, 'debit') - self::sumColumn($get, 'credit'), 2);
                        $balanced = abs($diff) < 0.005;

                        return self::totalLine(
                            __('admin.fields.difference'),
                            $diff,
                            $balanced ? 'rgb(22 163 74)' : 'rgb(220 38 38)',
                            $balanced ? ' ✓' : '',
                        );
                    }),
                ]),
        ]);
    }

    protected static function sumColumn(Get $get, string $column): float
    {
        $lines = $get('lines') ?? [];
        $sum = 0.0;
        foreach ($lines as $line) {
            $sum += (float) ($line[$column] ?? 0);
        }

        return round($sum, 2);
    }

    protected static function totalLine(string $label, float $value, ?string $color = null, string $suffix = ''): HtmlString
    {
        $valueStyle = $color ? 'color:'.$color.';' : '';

        return new HtmlString(
            '<div style="font-size:0.75rem;color:var(--fi-color-gray-500,#71717a)">'.e($label).'</div>'
            .'<div style="font-weight:600;'.$valueStyle.'">EGP '.number_format($value, 2).$suffix.'</div>'
        );
    }
}
