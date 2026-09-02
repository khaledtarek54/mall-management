<?php

namespace App\Filament\Admin\Resources\BankAccounts\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Resources\BankAccounts\BankAccountResource;
use App\Filament\Admin\Resources\Concerns\SavesTableViews;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBankAccounts extends ListRecords
{
    // Earned it on 2026-09-02, when `purpose` and `is_default` joined `is_active` and the trashed
    // filter and took this list over `SavedViews::THRESHOLD`. Adding the trait rather than writing
    // the registry's FIRST `NEVER` entry: both `ALWAYS` and `NEVER` are empty, so no list has ever
    // needed an exemption, and "this register is small" is a weaker claim than the rule it would be
    // the first to break — a portfolio with three malls banking in two places each already has more
    // rows than the filters were added for.
    use SavesTableViews;

    protected static string $resource = BankAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...$this->savedViewActions(),
            GuideAction::for(static::getResource()),
            CreateAction::make(),
        ];
    }
}
