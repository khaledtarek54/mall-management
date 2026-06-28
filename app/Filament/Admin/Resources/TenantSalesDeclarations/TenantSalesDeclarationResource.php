<?php

namespace App\Filament\Admin\Resources\TenantSalesDeclarations;

use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\Concerns\ScopesViaProperty;
use App\Filament\Admin\Resources\TenantSalesDeclarations\Pages\CreateTenantSalesDeclaration;
use App\Filament\Admin\Resources\TenantSalesDeclarations\Pages\EditTenantSalesDeclaration;
use App\Filament\Admin\Resources\TenantSalesDeclarations\Pages\ListTenantSalesDeclarations;
use App\Filament\Admin\Resources\TenantSalesDeclarations\Schemas\TenantSalesDeclarationForm;
use App\Filament\Admin\Resources\TenantSalesDeclarations\Tables\TenantSalesDeclarationsTable;
use App\Models\TenantSalesDeclaration;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class TenantSalesDeclarationResource extends Resource
{
    use RoleGatedActions;
    use ScopesViaProperty;

    protected static function tenantScopeRelation(): string
    {
        return 'lease.unit';
    }

    protected static function permissionModule(): string
    {
        return 'tenant_sales';
    }

    // A locked declaration is terminal/immutable — it has already created a
    // percentage_rent billing charge. Editing it (e.g. flipping status back to
    // 'submitted') could double-bill or strand the charge, so block edit
    // entirely; corrections go through the void action.
    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        if ($record->status === 'locked') {
            return false;
        }

        return static::hasPermission('edit');
    }

    protected static ?string $model = TenantSalesDeclaration::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static ?int $navigationSort = 6;

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.tenant_sales');
    }

    public static function getModelLabel(): string
    {
        return __('admin.resources.tenant_sales.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.tenant_sales.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.leasing');
    }

    public static function getNavigationBadge(): ?string
    {
        // Respect the active Filament tenant (Asset). ScopesViaProperty's
        // getEloquentQuery() applies the lease.unit.asset_id filter; the
        // ALL pseudo-asset bypasses scoping and returns the portfolio-wide
        // count of declarations awaiting admin review.
        $count = static::getEloquentQuery()
            ->where('status', 'submitted')
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'warning';
    }

    public static function form(Schema $schema): Schema
    {
        return TenantSalesDeclarationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TenantSalesDeclarationsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTenantSalesDeclarations::route('/'),
            'create' => CreateTenantSalesDeclaration::route('/create'),
            'edit' => EditTenantSalesDeclaration::route('/{record}/edit'),
        ];
    }

}
