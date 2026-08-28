<?php

namespace App\Filament\Admin\RelationManagers;

use App\Filament\Admin\RelationManagers\Concerns\CountsItsRows;
use App\Filament\Admin\Resources\FacilityWorkOrders\FacilityWorkOrderResource;
use App\Models\FacilityWorkOrder;
use App\Models\User;
use App\Models\VendorContact;
use App\Services\CommentOnWorkOrderService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * The work order's conversation — step 1 of `docs/modules/12b-VENDOR-PORTAL-DESIGN.md`.
 *
 * Deliberately the twin of `TenantRequestCommentsRelationManager` rather than a fresh design: that
 * screen already found the shapes that matter here — no search box on a thread, the author resolved
 * through the morph, and the internal/public toggle gated as a **disclosure** rather than treated as
 * an ordinary edit.
 */
class WorkOrderCommentsRelationManager extends RelationManager
{
    use CountsItsRows;

    protected static string $relationship = 'comments';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.facility.comments.title');
    }

    public function table(Table $table): Table
    {
        return $table
            // No search box: `FacilityWorkOrderComment` carries no `search_text` blob (nobody hunts
            // for a comment by name) and no column here is searchable — so without this,
            // TableDefaults would render a box that always returns nothing, which reads as "no such
            // row". Same reasoning as the tenant thread. See App\Support\SearchPolicy.
            ->searchable(false)
            ->modifyQueryUsing(fn ($query) => $query->with('author'))
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('admin.activity.when'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('author_label')
                    ->label(__('admin.activity.who'))
                    ->state(function ($record): string {
                        $author = $record->author;

                        // Named by WHAT they are, not just who: on a job the difference between a
                        // colleague and the contractor is the whole point of reading the thread.
                        if ($author instanceof User) {
                            return $author->name.' · '.__('admin.facility.comments.author_staff');
                        }
                        if ($author instanceof VendorContact) {
                            return $author->name.' · '.__('admin.facility.comments.author_contractor');
                        }

                        return __('admin.activity.system');
                    })
                    ->weight('medium'),
                TextColumn::make('body')
                    ->label(__('admin.facility.comments.body'))
                    ->wrap()
                    ->limit(200),
                IconColumn::make('is_internal')
                    ->label(__('admin.facility.comments.internal'))
                    ->boolean()
                    ->trueIcon('heroicon-o-lock-closed')
                    ->trueColor('warning')
                    ->falseIcon('heroicon-o-globe-alt')
                    ->falseColor('gray'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('admin.facility.comments.add'))
                    ->modalHeading(__('admin.facility.comments.add'))
                    // Gated in both places: Filament actions default to ALLOWED, and `canEdit()` is
                    // false for a terminal job — so no comment reaches a done or cancelled order
                    // from here, which the service refuses again underneath.
                    ->visible(fn (RelationManager $livewire) => FacilityWorkOrderResource::canEdit($livewire->getOwnerRecord()))
                    ->authorize(fn (RelationManager $livewire) => FacilityWorkOrderResource::canEdit($livewire->getOwnerRecord()))
                    ->schema([
                        Textarea::make('body')
                            ->label(__('admin.facility.comments.body'))
                            ->required()
                            ->rows(4)
                            ->columnSpanFull(),
                        Toggle::make('is_internal')
                            ->label(__('admin.facility.comments.is_internal'))
                            ->helperText(__('admin.facility.comments.is_internal_helper'))
                            ->default(false),
                    ])
                    ->using(function (array $data, RelationManager $livewire) {
                        /** @var FacilityWorkOrder $order */
                        $order = $livewire->getOwnerRecord();

                        return app(CommentOnWorkOrderService::class)->comment(
                            $order,
                            Auth::user(),
                            (string) $data['body'],
                            (bool) ($data['is_internal'] ?? false),
                        );
                    }),
            ])
            ->recordActions([
                Action::make('toggleVisibility')
                    ->label(fn ($record) => $record->is_internal
                        ? __('admin.facility.comments.make_public')
                        : __('admin.facility.comments.make_internal'))
                    ->icon('heroicon-o-eye-slash')
                    ->color('gray')
                    ->visible(fn (RelationManager $livewire) => FacilityWorkOrderResource::canEdit($livewire->getOwnerRecord()))
                    // Flipping this PUBLISHES a staff note to the contractor once the portal ships —
                    // a disclosure, not a cosmetic flag. Gated as well as hidden, for the reason the
                    // tenant thread states.
                    ->authorize(fn (RelationManager $livewire) => FacilityWorkOrderResource::canEdit($livewire->getOwnerRecord()))
                    ->requiresConfirmation()
                    ->action(fn ($record) => $record->update(['is_internal' => ! $record->is_internal])),
            ])
            ->toolbarActions([])
            ->defaultSort('created_at', 'asc')
            ->paginated([10, 25]);
    }
}
