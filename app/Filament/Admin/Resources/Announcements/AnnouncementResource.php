<?php

namespace App\Filament\Admin\Resources\Announcements;

use App\Filament\Admin\Resources\Announcements\Pages\CreateAnnouncement;
use App\Filament\Admin\Resources\Announcements\Pages\EditAnnouncement;
use App\Filament\Admin\Resources\Announcements\Pages\ListAnnouncements;
use App\Filament\Admin\Resources\Announcements\Pages\ViewAnnouncement;
use App\Filament\Admin\Resources\Announcements\RelationManagers\RecipientsRelationManager;
use App\Filament\Admin\Resources\Announcements\Schemas\AnnouncementForm;
use App\Filament\Admin\Resources\Announcements\Tables\AnnouncementsTable;
use App\Filament\Admin\Resources\Concerns\GuardsAssetInScope;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\Concerns\ScopesToProperty;
use App\Filament\Concerns\SearchesNormalizedText;
use App\Models\Announcement;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Mall news — the operator's notices to a property's tenants, delivered as bell + mobile push and
 * kept as a post the tenant can still read next month.
 *
 * Property-owned (direct asset_id). Gated by `announcements.*` permissions, with **send split from
 * create**: since notices gained a draft state, composing one and pushing it to every retailer's
 * phone stopped being the same act, and an assistant can reasonably hold one authority without the
 * other. Same reasoning that gives `marketing_posts` a separate `approve`.
 *
 * **Editing is state-dependent, not absent.** A draft or scheduled notice is ordinary content; a
 * SENT one is evidence — tenants hold a push quoting its text and `announcement_recipients`
 * records who received it — so `canEdit()` refuses it and the edit page re-checks. The old rule
 * ("no edit page at all, an announcement is immutable") was the right instinct applied one state
 * too early: it made composing and sending the same keystroke, which is why the Ramadan-hours
 * notice could only ever be written on the morning it went out.
 *
 * The target property is CLIENT-SUPPLIED (the operator picks which mall to
 * broadcast to), so this deliberately does NOT use Filament's tenancy ownership
 * (`$tenantOwnershipRelationshipName`): that registers a model `creating` hook
 * which force-associates asset_id with the *current* panel tenant, and in
 * "All Properties" mode the tenant is the ALL pseudo-asset — it would silently
 * overwrite the chosen property and broadcast to nobody (no unit belongs to ALL).
 * ScopesToProperty turns that hook off AND scopes reads from the model's own
 * #[PropertyOwned] and the submitted asset_id is re-validated by assertAssetInScope() on
 * create AND on edit (Filament only stamps asset_id on create, never on update).
 */
class AnnouncementResource extends Resource
{
    use GuardsAssetInScope;
    use RoleGatedActions;
    use ScopesToProperty;
    use SearchesNormalizedText;

    protected static ?string $model = Announcement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'title';

    protected static function permissionModule(): string
    {
        return 'announcements';
    }

    /**
     * May this operator push a notice to a property's tenants?
     *
     * **Named once and used everywhere** — the row action's `visible()`, the same action's
     * `abort_unless`, and both save pages. Naming the predicate is what keeps the UI gate and the
     * real gate from drifting into disagreement, which is the failure `visible()`-only actions
     * produce and no test at either end notices.
     */
    public static function canSend(): bool
    {
        return static::hasPermission('send');
    }

    /**
     * A notice stops being editable the moment it is broadcast. See the class docblock.
     */
    public static function canEdit(Model $record): bool
    {
        return $record instanceof Announcement
            && $record->isEditable()
            && static::hasPermission('edit');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.announcements.plural');
    }

    public static function getModelLabel(): string
    {
        return __('admin.announcements.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.announcements.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.marketing');
    }

    public static function form(Schema $schema): Schema
    {
        return AnnouncementForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AnnouncementsTable::configure($table);
    }

    /**
     * Who was sent it and who has opened it — the read receipts, on the notice's own screen.
     *
     * @return array<class-string>
     */
    public static function getRelations(): array
    {
        return [
            RecipientsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAnnouncements::route('/'),
            'create' => CreateAnnouncement::route('/create'),
            // The read-receipt screen. A sent notice has no edit page, so without this there is
            // nowhere for its recipient list to live.
            'view' => ViewAnnouncement::route('/{record}'),
            'edit' => EditAnnouncement::route('/{record}/edit'),
        ];
    }

    /**
     * Searched through the fold-normalized blob, never a raw column.
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
        ];
    }

    /**
     * Context under the title. A bare reference does not tell an operator whether the
     * row in front of them is the one they were hunting for.
     *
     * @param  Announcement  $record  Narrowed from Filament's Model signature so static analysis
     *                                can see the columns — the alternative was ten baseline entries.
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            __('admin.fields.description') => Str::limit((string) $record->body, 80),
        ];
    }
}
