<?php

namespace App\Filament\Admin\Resources\VendorDocumentTypes;

use App\Filament\Admin\Resources\Concerns\BypassesFilamentTenantAutoScope;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\VendorDocumentTypes\Pages\CreateVendorDocumentType;
use App\Filament\Admin\Resources\VendorDocumentTypes\Pages\EditVendorDocumentType;
use App\Filament\Admin\Resources\VendorDocumentTypes\Pages\ListVendorDocumentTypes;
use App\Filament\Admin\Resources\VendorDocumentTypes\Schemas\VendorDocumentTypeForm;
use App\Filament\Admin\Resources\VendorDocumentTypes\Tables\VendorDocumentTypesTable;
use App\Models\VendorDocumentType;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * أنواع مستندات الموردين — the compliance file Eltizam requires of a supplier.
 *
 * The screen exists for one field on it. `blocks_dispatch` decides whether a lapsed document stops
 * the vendor being sent onto the mall floor, and that ruling used to be an array literal in PHP: a
 * lapsed insurance certificate blocked, a lapsed tax card did not, and revising it needed a deploy.
 * An operator dealing with a government client may be told a lapsed social-insurance certificate
 * (شهادة تأمينات اجتماعية) blocks too, because the principal carries the contractor's unpaid
 * contributions.
 *
 * **Operator-level, not per property** (`#[PortfolioShared]`): what a supplier must hold on file is
 * one policy across the portfolio.
 */
class VendorDocumentTypeResource extends Resource
{
    // PORTFOLIO-SHARED, so it must opt OUT of the panel's tenancy. Filament scopes a resource by
    // asking the model for an `asset` relationship, and a shared catalogue has none — the list page
    // 500'd with a LogicException the moment a property was selected, which is every page load.
    use BypassesFilamentTenantAutoScope;
    use RoleGatedActions;

    protected static ?string $model = VendorDocumentType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static ?int $navigationSort = 17;

    protected static function permissionModule(): string
    {
        return 'vendor_document_types';
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.vendor_document_types_screen.plural');
    }

    public static function getModelLabel(): string
    {
        return __('admin.vendor_document_types_screen.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.vendor_document_types_screen.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.payables');
    }

    public static function form(Schema $schema): Schema
    {
        return VendorDocumentTypeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return VendorDocumentTypesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVendorDocumentTypes::route('/'),
            'create' => CreateVendorDocumentType::route('/create'),
            'edit' => EditVendorDocumentType::route('/{record}/edit'),
        ];
    }
}
