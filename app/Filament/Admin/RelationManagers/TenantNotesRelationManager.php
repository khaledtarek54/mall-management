<?php

namespace App\Filament\Admin\RelationManagers;

use App\Filament\Admin\Actions\TenantNoteActions;
use App\Support\Filament\RefreshesOnRecordChange;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class TenantNotesRelationManager extends RelationManager
{
    /**
     * THE ACT THAT WRITES THESE ROWS IS NOT ON THIS COMPONENT ANY MORE, SO IT HAS TO BE TOLD.
     *
     * `HasRelationManagers` mounts each manager with a STABLE `key()`, which is exactly what tells
     * Livewire 3 to leave a child alone when the parent re-renders — so `ViewTenant`'s header act
     * saved a note and this table went on showing the rows from before the click, under a success
     * toast. An operator reads that as "it did not save" and logs the call twice.
     *
     * It did not need this while the CreateAction lived here: the component that handled the click
     * was the component whose table needed re-rendering. Moving an act to the page header is
     * precisely the case `RecordChanged` exists for, and the listener is what completes it.
     *
     * Nothing else in the panel needs it today, and the reason is worth stating: this is the only
     * relation manager whose rows are written by an act on its OWNER's page. The day another act
     * moves the same way, its manager needs this line too — a green test that asserts the ROW is
     * structurally unable to notice, which is why the test for this asserts the TABLE.
     */
    use RefreshesOnRecordChange;

    protected static string $relationship = 'notes';

    /**
     * READ-ONLY ON A VIEW PAGE, like every other relation manager in the panel.
     *
     * This one used to waive that (`isReadOnly(): false`), and the reason was real: measured
     * across all 14 roles, `customer_service` is the front desk and holds exactly `tenants.view`,
     * `notes.view`, `notes.create` and no `tenants.edit`, so `ViewTenant` is the only tenant
     * screen they can open and logging the call they had just taken was refused everywhere else.
     * A right that reads as granted and reaches no screen is the {@see \App\Support\PermissionReach}
     * failure exactly.
     *
     * What the waiver bought was reachability; what it cost was a read-only page rendering
     * *Log communication*, *Edit* and *Delete* inside one of its tabs. The act now lives on
     * `ViewTenant`'s HEADER instead ({@see \App\Filament\Admin\Actions\TenantNoteActions}),
     * which is where this panel puts acts — so the role keeps its one function, the tab keeps
     * Filament's default, and the two surfaces render one shared form.
     */
    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.relation_managers.notes');
    }

    public function form(Schema $schema): Schema
    {
        // Shared with the header act on `ViewTenant`, so the fields cannot depend on which page
        // the operator happened to be standing on when they logged the call.
        return $schema->components(TenantNoteActions::formComponents());
    }

    public function table(Table $table): Table
    {
        return $table
            // No search box: Note carries no `search_text` blob (it is not a
            // record anyone hunts for by name) and this table marks no column
            // searchable. Without this, TableDefaults' blob search would still render
            // the box — and a search box that always returns nothing is worse than
            // none, because it reads as "no such row". See App\Support\SearchPolicy.
            ->searchable(false)
            ->modifyQueryUsing(fn ($query) => $query->with('author'))
            ->columns([
                TextColumn::make('contacted_at')
                    ->label(__('admin.fields.contacted_at'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('channel')
                    ->label(__('admin.fields.note_channel'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.enums.note_channel.{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        'call' => 'info',
                        'whatsapp' => 'success',
                        'email' => 'warning',
                        'meeting' => 'primary',
                        'site_visit' => 'gray',
                        default => 'gray',
                    }),
                TextColumn::make('subject')
                    ->label(__('admin.fields.note_subject'))
                    ->placeholder('—')
                    ->limit(40),
                TextColumn::make('body')
                    ->label(__('admin.fields.note_body'))
                    ->limit(80)
                    ->wrap(),
                TextColumn::make('author.name')
                    ->label(__('admin.fields.note_author'))
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('channel')
                    ->label(__('admin.filters.channel'))
                    ->options(fn () => __('admin.enums.note_channel')),
            ])
            ->defaultSort('contacted_at', 'desc')
            ->headerActions([
                CreateAction::make()
                    ->label(__('admin.actions.log_communication'))
                    ->modalHeading(__('admin.actions.log_communication'))
                    ->visible(fn () => auth()->user()?->can('notes.create') ?? false)
                    ->authorize(fn () => auth()->user()?->can('notes.create') ?? false)
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['author_id'] ??= auth()->id();

                        return $data;
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn () => auth()->user()?->can('notes.edit') ?? false)
                    ->authorize(fn () => auth()->user()?->can('notes.edit') ?? false),
                DeleteAction::make()
                    ->visible(fn () => auth()->user()?->hasRole('super_admin') ?? false)
                    ->authorize(fn () => auth()->user()?->hasRole('super_admin') ?? false),
            ]);
    }
}
