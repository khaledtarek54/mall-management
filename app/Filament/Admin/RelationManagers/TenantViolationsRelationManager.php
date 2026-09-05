<?php

namespace App\Filament\Admin\RelationManagers;

use App\Filament\Admin\RelationManagers\Concerns\CountsItsRows;
use App\Filament\Admin\Resources\Violations\ViolationResource;
use App\Models\Violation;
use App\Models\ViolationCategory;
use App\Support\ResourceLink;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * This tenant's compliance history — the tab the 360 view was missing (UX5-08).
 *
 * "Have they been a problem?" is one of the questions a tenant record exists to answer, and it was
 * answerable only from the violations register filtered by hand. It belongs beside the requests
 * and the ledger: a repeat offender is a commercial fact about a tenancy, not a facilities note.
 *
 * READ-ONLY, and the reasoning is the same as the sales tab's. Recording a violation carries rules
 * that live in the resource — the category names the standard fine, a fine becomes an invoice
 * through `BillViolationFineService`, and a billed violation freezes. A thinner form here would
 * own none of them, so the header action LINKS to the real one with the tenant carried across.
 */
class TenantViolationsRelationManager extends RelationManager
{
    use CountsItsRows;

    protected static string $relationship = 'violations';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return ViolationResource::getPluralModelLabel();
    }

    /**
     * Gated on the violations module AND the reader's own right to see one. A tenant record is
     * opened by roles that hold nothing in this module, and a tab that 403s on click is worse
     * than a tab that is not there.
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return ViolationResource::canViewAny();
    }

    public function table(Table $table): Table
    {
        return $table
            // No search box: a violation is identified by its date and category, and `Violation`
            // carries no search blob — a box that matches nothing reads as "no such violation".
            ->searchable(false)
            ->columns([
                TextColumn::make('violation_date')
                    ->label(__('admin.violations.fields.violation_date'))
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('category')
                    ->label(__('admin.violations.fields.category'))
                    ->badge()
                    ->color('gray')
                    // Through the CATALOGUE, exactly as the register's own column does: an operator
                    // may add or retire a category, and a retired one must still label the rows
                    // that carry it (IsCodeCatalogue::labelFor reads inactive rows on purpose).
                    ->formatStateUsing(fn (?string $state) => ViolationCategory::labelFor($state)),

                TextColumn::make('fine_amount')
                    ->label(__('admin.violations.fields.fine_amount'))
                    ->money('EGP')
                    ->placeholder('—'),

                TextColumn::make('status')
                    ->label(__('admin.violations.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.statuses.violation.$state"))
                    ->color(fn (string $state) => match ($state) {
                        Violation::STATUS_RESOLVED => 'success',
                        default => 'warning',
                    }),
            ])
            ->headerActions([
                Action::make('record')
                    ->label(__('admin.actions.record_violation'))
                    ->icon('heroicon-o-plus')
                    ->visible(fn (): bool => ViolationResource::canCreate())
                    // **`for_tenant`, NOT `tenant`.** `tenant` is Filament's own TENANCY route
                    // parameter, so `getUrl('create', ['tenant' => $id])` puts the tenant's id in
                    // the path where the mall's slug belongs — `/admin/2/violations/create` — and
                    // the page 404s. CLAUDE.md records this exact trap from `CreatePayment`, and
                    // this walked into it anyway, which is why the test below now drives the URL
                    // through the real route rather than asserting the action exists.
                    ->url(fn (RelationManager $livewire): string => ResourceLink::create(ViolationResource::class, [
                        'for_tenant' => $livewire->getOwnerRecord()->getKey(),
                    ])),
            ])
            ->recordActions([
                Action::make('open')
                    ->label(__('admin.actions.open'))
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (Violation $record): string => ViolationResource::getUrl('edit', ['record' => $record]))
                    ->visible(fn (Violation $record): bool => ViolationResource::canEdit($record)),
            ])
            // Newest first: this is a LEDGER of dated events, and the recent ones are the ones a
            // leasing decision turns on (App\Support\TableSortPolicy).
            ->defaultSort('violation_date', 'desc')
            ->emptyStateIcon('heroicon-o-shield-check')
            ->emptyStateHeading(__('admin.tenant_violations.empty_heading'))
            ->emptyStateDescription(__('admin.tenant_violations.empty_description'));
    }
}
