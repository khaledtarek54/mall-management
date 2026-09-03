<?php

namespace App\Filament\Admin\Resources\AccountMappings\Schemas;

use App\Models\AccountMapping;
use App\Models\LedgerAccount;
use App\Support\Filament\EntitySelect;
use App\Support\Filament\PropertyField;
use App\Support\PostingRoleExposure;
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
                        // ── WHAT RE-POINTING THIS ROLE WILL DO TO WHAT IS ALREADY POSTED (SW-134) ──
                        //
                        // Accounts are resolved at PAYLOAD time and never frozen onto the entry, and
                        // `LedgerPoster::matches()` includes `ledger_account_id` in its line
                        // signature — so changing this row means the next `accounting:sync-ledger`
                        // sweep voids and re-posts every historical document that used it, up to a
                        // week later, with nobody having confirmed it. Nothing gated that: the
                        // model guards duplicates and the deletion of a global default,
                        // `SealedPeriod` returns early because `AccountMapping` is not a GL source,
                        // and `ChangeImpact` classifies the columns of sources rather than of a
                        // configuration table.
                        //
                        // Measured on the QA baseline: 487 posted lines on `accounts_receivable`.
                        //
                        // The split between OPEN and CLOSED is the substance. An open-period entry
                        // is re-derived and the books stay coherent; a closed-period one CANNOT be,
                        // so it keeps the old account while the mapping says otherwise and
                        // `billing:reconcile --deep` reports drift for ever — which turns
                        // `atriom:preflight` permanently red and blocks the next deploy for a reason
                        // that has nothing to do with the deploy.
                        //
                        // This WARNS; it does not refuse. Whether a mapping change should be
                        // PROSPECTIVE is an accounting decision nobody has taken (Yardi's answer is
                        // that it is), and refusing on this operator's behalf would be taking it.
                        ->hint(fn (?AccountMapping $record) => $record
                            ? PostingRoleExposure::warningFor($record->ledger_account_id)
                            : null)
                        ->hintColor('warning')
                        ->helperText(__('admin.helpers.posting_map_account')),

                    // Null = the global default every property falls back to. A property here makes
                    // the row an override that wins for that mall only. Scoped to what this operator
                    // may see, so an override cannot be aimed at another operator's property.
                    // FREE by design — see PropertyField::PORTFOLIO_LEVEL. The blank row is the
                    // global default every property inherits, not an accident.
                    PropertyField::scope(allMeans: __('admin.posting_map.global'))
                        ->helperText(__('admin.helpers.posting_map_property')),
                ]),
        ]);
    }
}
