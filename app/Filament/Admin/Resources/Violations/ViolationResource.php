<?php

namespace App\Filament\Admin\Resources\Violations;

use App\Filament\Admin\Resources\Concerns\GuardsAssetInScope;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\Concerns\ScopesToProperty;
use App\Filament\Admin\Resources\Violations\Pages\CreateViolation;
use App\Filament\Admin\Resources\Violations\Pages\EditViolation;
use App\Filament\Admin\Resources\Violations\Pages\ListViolations;
use App\Filament\Admin\Resources\Violations\Schemas\ViolationForm;
use App\Filament\Admin\Resources\Violations\Tables\ViolationTable;
use App\Filament\Concerns\SearchesNormalizedText;
use App\Models\Tenant;
use App\Models\Violation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The tenant-violations register (module 31) — FR-REQ-15/16/17. Property-scoped
 * (direct asset_id, like Area / Equipment); gated by the `violations.*`
 * permissions; lives in the Operations navigation group.
 *
 * FR-REQ-15 records the fine (`fine_amount`) — it is NOT billed here. FR-REQ-16
 * is the property-scoped table + record view for authorized staff. FR-REQ-17 is
 * the "Send notice" record action (ViolationTable), gated on `violations.notify`.
 */
class ViolationResource extends Resource
{
    // NOT Filament auto-tenancy: asset_id is CLIENT-supplied (the operator picks the mall the
    // violation occurred in, and that Select is enabled in All-Properties mode). Filament's
    // ownership `creating` hook would force asset_id to the current tenant — and in All-mode the
    // tenant is the ALL pseudo-asset, silently clobbering the chosen mall (the "Announcements
    // tenancy trap"). ScopesToProperty turns that hook off AND scopes reads from the model's own
    // #[PropertyOwned]; the submitted asset_id is re-validated by assertAssetInScope().
    use GuardsAssetInScope;
    use RoleGatedActions;
    use ScopesToProperty;
    use SearchesNormalizedText;

    protected static ?string $model = Violation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'reference';

    protected static function permissionModule(): string
    {
        return 'violations';
    }

    /**
     * Whether the current user may send the tenant notice (FR-REQ-17). Named once
     * so the table action gates the SAME predicate in visible() (the UI) and
     * action()/authorize() (the real dispatch gate) — they can't drift.
     */
    public static function canNotify(Model $record): bool
    {
        return static::hasPermission('notify');
    }

    /**
     * Whether the current user may bill this violation's fine to the tenant. Gated on `invoices.create`
     * (it issues AR, like the utility recharge), and only offered for a fined, not-yet-billed
     * violation. Named once so the table action gates the SAME predicate in visible() (UI) and
     * action()/authorize() (the real dispatch gate) — they can't drift. (A missing active LEASE is
     * dynamic, so it stays a runtime toast, not a hidden button.)
     */
    public static function canBillFine(Violation $record): bool
    {
        return $record->fine_amount !== null
            && (float) $record->fine_amount > 0
            && ! $record->isBilled()
            && (auth()->user()?->can('invoices.create') ?? false);
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.violations.plural');
    }

    public static function getModelLabel(): string
    {
        return __('admin.violations.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.violations.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.operations');
    }

    public static function form(Schema $schema): Schema
    {
        return ViolationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ViolationTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListViolations::route('/'),
            'create' => CreateViolation::route('/create'),
            'edit' => EditViolation::route('/{record}/edit'),
        ];
    }

    /**
     * By the VIO- reference printed on the notice, by what was breached, or by tenant.
     *
     * Every path ends in `search_text` on purpose — see
     * App\Filament\Concerns\SearchesNormalizedText.
     *
     * @return array<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return [
            'search_text',
            'tenant.search_text',
        ];
    }

    /**
     * Context under the title. A bare reference does not tell an operator whether the
     * row in front of them is the one they were hunting for.
     *
     * @param  Violation  $record  Narrowed from Filament's Model signature so static analysis
     *                             can see the columns — the alternative was ten baseline entries.
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var Tenant|null $tenant */
        $tenant = $record->tenant;

        return [
            __('admin.tables.common.tenant') => $tenant?->name,
            __('admin.fields.category') => $record->category,
            __('admin.fields.amount') => 'EGP '.number_format((float) $record->fine_amount, 2),
        ];
    }

    /**
     * Eager-load exactly what getGlobalSearchResultDetails() reaches for. Without this
     * the details above fire one query per row, per keystroke, on top of the search.
     */
    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with(['tenant']);
    }
}
