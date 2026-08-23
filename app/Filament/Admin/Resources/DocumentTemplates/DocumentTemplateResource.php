<?php

namespace App\Filament\Admin\Resources\DocumentTemplates;

use App\Filament\Admin\Resources\Concerns\BypassesFilamentTenantAutoScope;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\DocumentTemplates\Pages\CreateDocumentTemplate;
use App\Filament\Admin\Resources\DocumentTemplates\Pages\EditDocumentTemplate;
use App\Filament\Admin\Resources\DocumentTemplates\Pages\ListDocumentTemplates;
use App\Filament\Admin\Resources\DocumentTemplates\Schemas\DocumentTemplateForm;
use App\Filament\Admin\Resources\DocumentTemplates\Tables\DocumentTemplatesTable;
use App\Models\DocumentTemplate;
use App\Support\TenantScope;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * نصوص المستندات — the standing wording on a tenant-facing document (EG-15, finding S-6).
 *
 * The screen is the whole point. Every word on an invoice was a translation key, so changing the
 * footer — the line that tells a tenant when payment is due and how to make it — was a deploy.
 *
 * **Property-owned, with a null asset meaning the portfolio default.** A mall may override any
 * block; bank details are the case that forces it, since two malls banking in two places is exactly
 * what EG-12 built `bank_accounts` for.
 */
class DocumentTemplateResource extends Resource
{
    // The create form exposes an EDITABLE `asset_id` — blank is the house default and choosing a
    // mall is the override — so the panel's tenant auto-stamp must be off. Left on, it clobbers the
    // operator's blank to the selected mall and the house row becomes unwritable through its own
    // form. That is the "Announcements tenancy trap", and the gate names it.
    use BypassesFilamentTenantAutoScope;
    use RoleGatedActions;

    protected static ?string $model = DocumentTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static function permissionModule(): string
    {
        return 'document_templates';
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.document_templates_screen.plural');
    }

    public static function getModelLabel(): string
    {
        return __('admin.document_templates_screen.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.document_templates_screen.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return DocumentTemplateForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DocumentTemplatesTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        // Hybrid scope, the same one DepartmentResource uses: the house row (null asset_id) is
        // visible from every mall, and a property row only within the user's visible set. Scoping
        // this strictly would hide the default — the row an operator writes first — from every
        // screen, and neither mistake throws.
        $ids = TenantScope::visibleAssetIds();

        if ($ids !== null) {
            $query->where(fn (Builder $q) => $q->whereNull('asset_id')->orWhereIn('asset_id', $ids));
        }

        return $query;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDocumentTemplates::route('/'),
            'create' => CreateDocumentTemplate::route('/create'),
            'edit' => EditDocumentTemplate::route('/{record}/edit'),
        ];
    }
}
