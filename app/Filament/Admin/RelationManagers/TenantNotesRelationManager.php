<?php

namespace App\Filament\Admin\RelationManagers;

use App\Models\Note;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class TenantNotesRelationManager extends RelationManager
{
    protected static string $relationship = 'notes';

    /**
     * NOT read-only on a View page — the one relation manager in the panel that says so.
     *
     * Filament makes every relation manager read-only when it hangs off a `ViewRecord`, and denies
     * its Create/Edit/Delete actions before their own gates are ever consulted. For most of the 61
     * here that is a sensible default. For this one it makes a designed role's ONLY function
     * unreachable: `customer_service` is the front desk — `tenants.view`, `notes.view`,
     * `notes.create`, and deliberately no `tenants.edit` — so `ViewTenant` is the only tenant screen
     * they can open, and logging the call they just took was refused there. A right that reads as
     * granted and reaches no screen is the `App\Support\PermissionReach` failure exactly, and it is
     * the confusing direction: the role appears to hold it, the screen refuses, and nobody can tell
     * policy from bug.
     *
     * "The page is a View page" is a UI inference, not an authorization fact — this panel has no
     * policies and gates on permissions at the call site. So the answer is to let the call site
     * answer: all three actions below carry `->authorize()` on `notes.create` / `notes.edit` /
     * super_admin, and `RelationManagerCrudIsGatedConformanceTest` is what keeps that true. Waiving
     * the default here without those gates would have opened the screen to anyone who can read it.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.relation_managers.notes');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('channel')
                ->label(__('admin.fields.note_channel'))
                ->options(fn () => __('admin.enums.note_channel'))
                ->default('call')
                ->required()
                ->native(false),
            DateTimePicker::make('contacted_at')
                ->label(__('admin.fields.contacted_at'))
                ->default(fn () => now())
                ->displayFormat('d/m/Y H:i')
                ->required(),
            TextInput::make('subject')
                ->label(__('admin.fields.note_subject'))
                ->maxLength(150)
                ->columnSpanFull(),
            Textarea::make('body')
                ->label(__('admin.fields.note_body'))
                ->required()
                ->rows(4)
                ->columnSpanFull(),
        ]);
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
