<?php

namespace App\Filament\Admin\Resources\ApprovalRules;

use App\Filament\Admin\Resources\ApprovalRules\Pages\CreateApprovalRule;
use App\Filament\Admin\Resources\ApprovalRules\Pages\EditApprovalRule;
use App\Filament\Admin\Resources\ApprovalRules\Pages\ListApprovalRules;
use App\Filament\Admin\Resources\ApprovalRules\Schemas\ApprovalRuleForm;
use App\Filament\Admin\Resources\ApprovalRules\Tables\ApprovalRulesTable;
use App\Filament\Admin\Resources\Concerns\BypassesFilamentTenantAutoScope;
use App\Models\ApprovalRule;
use App\Support\Modules;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * حدود الاعتماد — the approval ladder as rows an operator edits (FR-CM-11, FR-PROC-02).
 *
 * The bands existed and were enforced; the only way to change one was a seeder and a deploy. So the
 * ladder the FRD describes as company policy was, in practice, a developer's constant — and a policy
 * nobody can change without engineering is one that stops matching how the company actually signs
 * things off.
 *
 * **Shared, deliberately.** `ApprovalRule` carries no `asset_id` (see the model): approval authority
 * is a company rule, unlike SLA, which the FRD explicitly wants per mall. Registered as SHARED in
 * `App\Support\PropertyIsolation`, so this resource is not property-scoped and must not become so
 * without that decision being revisited.
 *
 * **One permission, not the usual four.** `approvals.manage_rules` already exists and is described
 * as "configure the approval bands" — you either administer the ladder or you do not, and a
 * view-only band list is of no use to anyone who cannot act on it. It is withheld from `manager` in
 * `RolesPermissionsSeeder` for the same reason `approvals.tier_3` is: a ladder whose rungs the people
 * climbing it can rewrite is not a ladder. So this gates explicitly rather than through
 * `RoleGatedActions`, whose `{module}.{action}` convention would invent four permissions that say
 * nothing the one already says.
 */
class ApprovalRuleResource extends Resource
{
    use BypassesFilamentTenantAutoScope;

    protected static ?string $model = ApprovalRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckBadge;

    protected static ?string $recordTitleAttribute = 'module';

    /** The single gate. Named once so the sidebar, the pages and every action agree. */
    public static function canManage(): bool
    {
        // This resource does not use RoleGatedActions (its CRUD all resolves to one predicate), so
        // the module flag has to be stated here rather than inherited. Named once, so the five
        // `can*` methods below and `shouldRegisterNavigation()` cannot drift apart.
        return Modules::enabled('approvals')
            && (Auth::user()?->can('approvals.manage_rules') ?? false);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return self::canManage();
    }

    public static function canViewAny(): bool
    {
        return self::canManage();
    }

    public static function canView(Model $record): bool
    {
        return self::canManage();
    }

    public static function canCreate(): bool
    {
        return self::canManage();
    }

    public static function canEdit(Model $record): bool
    {
        return self::canManage();
    }

    /**
     * A band is configuration, not a money record — `DeletionPolicy` classifies it ALLOWED for
     * exactly that reason. Deleting one is still not neutral: with no band covering an amount,
     * `ApprovalPolicy` falls back to the STRICTEST tier configured, so removing a rung makes the
     * gate harder, never softer. Safe by construction, which is why this follows the project-wide
     * rule (delete = super_admin) rather than inventing a looser one.
     */
    public static function canDelete(Model $record): bool
    {
        return self::canManage() && (Auth::user()?->hasRole('super_admin') ?? false);
    }

    public static function canDeleteAny(): bool
    {
        return self::canManage() && (Auth::user()?->hasRole('super_admin') ?? false);
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.approval_rules');
    }

    public static function getModelLabel(): string
    {
        return __('admin.resources.approval_rule.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.approval_rule.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return ApprovalRuleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ApprovalRulesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListApprovalRules::route('/'),
            'create' => CreateApprovalRule::route('/create'),
            'edit' => EditApprovalRule::route('/{record}/edit'),
        ];
    }
}
