<?php

namespace App\Filament\Admin\Resources\AccountMappings\Schemas;

use App\Models\LedgerAccount;
use App\Support\PostingRoles;
use App\Support\TenantScope;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

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
                            : __('admin.helpers.posting_role')),

                    // Postable accounts only. `AccountResolver` refuses a summary or inactive account
                    // at posting time, which would surface as a failed journal entry long after the
                    // mapping was saved — filtering here moves that refusal to the moment of the
                    // mistake instead.
                    Select::make('ledger_account_id')
                        ->label(__('admin.fields.ledger_account'))
                        ->options(fn () => LedgerAccount::query()
                            ->where('is_postable', true)
                            ->where('is_active', true)
                            ->orderBy('code')
                            ->get()
                            ->mapWithKeys(fn (LedgerAccount $a) => [$a->id => "{$a->code} — {$a->name_ar}"])
                            ->all())
                        ->required()
                        ->searchable()
                        ->native(false)
                        ->helperText(__('admin.helpers.posting_map_account')),

                    // Null = the global default every property falls back to. A property here makes
                    // the row an override that wins for that mall only. Scoped to what this operator
                    // may see, so an override cannot be aimed at another operator's property.
                    Select::make('asset_id')
                        ->label(__('admin.fields.property'))
                        ->options(fn () => TenantScope::selectableAssetOptions())
                        ->placeholder(__('admin.posting_map.global'))
                        ->searchable()
                        ->native(false)
                        ->helperText(__('admin.helpers.posting_map_property')),
                ]),
        ]);
    }
}
