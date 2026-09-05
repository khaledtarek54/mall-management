<?php

namespace App\Filament\Admin\Resources\Tenants\Pages;

use App\Filament\Admin\Actions\TenantActions;
use App\Filament\Admin\Resources\Concerns\FillsCustomFields;
use App\Filament\Admin\Resources\Tenants\TenantResource;
use App\Models\Tenant;
use App\Services\TenantStatementPdfService;
use App\Support\Filament\PdfDownloadAction;
use App\Support\Filament\RefreshesRecordState;
use App\Support\TenantScope;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

class EditTenant extends EditRecord
{
    use FillsCustomFields;
    use RefreshesRecordState;

    /**
     * Nothing on this page derives `status` any more — the header action that activated a tenant as
     * a side effect of issuing credentials was removed on 2026-09-05 when the two tenant-facing
     * logins merged. Kept declared and empty because a record page that refills a field the
     * operator is typing discards their edit, and the honest answer here is "nothing".
     *
     * @return array<int, string>
     */
    protected function derivedStatePaths(): array
    {
        return [];
    }

    protected static string $resource = TenantResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // The same three acts `ViewTenant` carries, from one definition: an act belongs to the
            // record and appears by permission, not by which page the operator opened. Two of them
            // used to be header buttons on the payments and violations TABS, where a read-only page
            // could not deny them because a `->url()` link is not an action.
            ...TenantActions::all(),
            PdfDownloadAction::make('statement')
                ->label(__('admin.statement.action_label'))
                ->icon(Heroicon::OutlinedDocumentArrowDown)
                ->recipient(fn (Tenant $record) => $record)
                // Scoped, exactly as the two sibling call sites are (`TenantsTable`,
                // `ArCollections`), each with a comment saying why. Omitting it here meant a
                // property-restricted operator could download a shared tenant's WHOLE-PORTFOLIO
                // statement — every filter in `data()` is `->when($visibleAssetIds !== null, …)`,
                // so null is unrestricted, rollups included. A tenant leasing in two malls is
                // legitimately on either mall's register, so this needed no special access.
                // Secondary, and true even for super_admin: two identically-labelled buttons
                // produced DIFFERENT documents for the same tenant.
                ->document(fn (Tenant $record, string $locale): string => app(TenantStatementPdfService::class)
                    ->build($record, TenantScope::visibleAssetIds(), null, null, $locale))
                ->filename(fn (Tenant $record): string => app(TenantStatementPdfService::class)->filename($record))
                // Statement is tenant financial data — gate server-side (was ungated).
                ->authorize(fn () => Auth::user()?->can('tenants.view') ?? false),
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
