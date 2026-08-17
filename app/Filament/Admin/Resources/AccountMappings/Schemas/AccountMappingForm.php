<?php

namespace App\Filament\Admin\Resources\AccountMappings\Schemas;

use App\Models\Asset;
use App\Models\LedgerAccount;
use App\Support\Filament\EntitySelect;
use App\Support\PostingRoles;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class AccountMappingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('admin.sections.posting_map'))
                ->description(__('admin.helpers.posting_map_section'))
                ->columns(2)
                ->components([
                    // A picker, never a text box. `key` is a plain string column, and a row whose key
                    // is misspelled maps nothing at all: the resolver never asks for that spelling,
                    // so nothing throws — the real role is simply left unmapped behind a row that
                    // looks saved. See App\Support\PostingRoles.
                    Select::make('key')
                        ->label(__('admin.fields.posting_role'))
                        ->options(fn () => PostingRoles::groupedOptions())
                        ->required()
                        ->searchable()
                        ->native(false)
                        ->helperText(fn (Get $get) => ($group = PostingRoles::group((string) $get('key')))
                            ? __('admin.helpers.posting_role_expects', ['group' => PostingRoles::groupLabel($group)])
                            : __('admin.helpers.posting_role'))
                        ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.posting_role')),

                    // Postable accounts only. `AccountResolver` refuses a summary or inactive account
                    // at posting time, which would surface as a failed journal entry long after the
                    // mapping was saved — filtering here moves that refusal to the moment of the
                    // mistake instead.
                    EntitySelect::make('ledger_account_id')
                        ->label(__('admin.fields.ledger_account'))
                        ->entity(LedgerAccount::class)
                        // The narrowing stays — the presentation does not: the account name came
                        // from `name_ar` regardless of the reader's language, so an English session
                        // read the chart in Arabic. `LedgerAccount::displayName()` answers for the
                        // active locale, and the picker now reads it through OptionDisplay.
                        ->modifyOptionsQuery(fn ($query) => $query
                            ->where('is_postable', true)
                            ->where('is_active', true))
                        ->required()
                        ->helperText(__('admin.helpers.posting_map_account')),

                    // Null = the global default every property falls back to. A property here makes
                    // the row an override that wins for that mall only. Scoped to what this operator
                    // may see, so an override cannot be aimed at another operator's property.
                    EntitySelect::make('asset_id')
                        ->label(__('admin.fields.property'))
                        ->entity(Asset::class)
                        ->placeholder(__('admin.posting_map.global'))
                        ->searchable()
                        ->native(false)
                        ->helperText(__('admin.helpers.posting_map_property')),
                ]),
        ]);
    }
}
