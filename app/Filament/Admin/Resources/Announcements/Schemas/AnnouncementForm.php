<?php

namespace App\Filament\Admin\Resources\Announcements\Schemas;

use App\Models\Announcement;
use App\Services\SendAnnouncementAction;
use App\Support\TenantScope;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Compose a notice to a property's tenants.
 *
 * **`delivery` is a choice, not the `status` column.** The operator decides "now / at a time /
 * not yet", and only {@see SendAnnouncementAction} may ever write `status = sent` —
 * because that word means "tenants have been pushed this text", which is a fact about the world
 * and not an intention. The create/edit pages translate the choice; the column records what
 * happened. Conflating them is how a record ends up claiming a broadcast that never left the
 * queue.
 *
 * Both language columns sit side by side rather than behind tabs: a notice written in one language
 * and not the other is the single most likely defect on this screen, and a tab hides it.
 */
class AnnouncementForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('admin.announcements.sections.audience'))
                ->icon(Heroicon::OutlinedBuildingStorefront)
                ->columns(2)
                ->schema([
                    Select::make('asset_id')
                        ->label(__('admin.announcements.fields.property'))
                        // Scoped to the user's visible properties (never leaks another mall).
                        ->options(fn () => TenantScope::selectableAssetOptions())
                        ->default(fn () => TenantScope::currentAssetId())
                        ->disabled(fn () => TenantScope::currentAssetId() !== null)
                        ->dehydrated()
                        ->required()
                        ->native(false)
                        ->helperText(__('admin.announcements.fields.property_hint')),

                    Select::make('category')
                        ->label(__('admin.announcements.fields.category'))
                        ->options(fn () => collect(Announcement::CATEGORIES)
                            ->mapWithKeys(fn (string $c) => [$c => __("admin.announcements.categories.{$c}")]))
                        ->default(Announcement::CATEGORY_GENERAL)
                        ->required()
                        ->native(false)
                        ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.announcement_category')),
                ]),

            Section::make(__('admin.announcements.sections.message'))
                ->icon(Heroicon::OutlinedChatBubbleLeftRight)
                ->columns(2)
                ->schema([
                    TextInput::make('title')
                        ->label(__('admin.announcements.fields.title'))
                        ->required()
                        ->maxLength(120),

                    TextInput::make('title_ar')
                        ->label(__('admin.announcements.fields.title_ar'))
                        ->maxLength(120)
                        ->helperText(__('admin.announcements.fields.arabic_hint')),

                    Textarea::make('body')
                        ->label(__('admin.announcements.fields.body'))
                        ->required()
                        ->maxLength(1000)
                        ->rows(6),

                    Textarea::make('body_ar')
                        ->label(__('admin.announcements.fields.body_ar'))
                        ->maxLength(1000)
                        ->rows(6),

                    SpatieMediaLibraryFileUpload::make('hero')
                        ->label(__('admin.announcements.fields.hero'))
                        ->helperText(__('admin.announcements.fields.hero_hint'))
                        ->collection(Announcement::HERO_COLLECTION)
                        ->disk('local')
                        ->image()
                        ->imageEditor()
                        ->imageEditorAspectRatios(['16:9'])
                        ->maxSize(5120)
                        ->columnSpanFull(),
                ]),

            Section::make(__('admin.announcements.sections.delivery'))
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->columns(2)
                ->schema([
                    Radio::make('delivery')
                        ->label(__('admin.announcements.fields.delivery'))
                        ->options([
                            'now' => __('admin.announcements.delivery.now'),
                            'schedule' => __('admin.announcements.delivery.schedule'),
                            'draft' => __('admin.announcements.delivery.draft'),
                        ])
                        ->descriptions([
                            'now' => __('admin.announcements.delivery.now_hint'),
                            'schedule' => __('admin.announcements.delivery.schedule_hint'),
                            'draft' => __('admin.announcements.delivery.draft_hint'),
                        ])
                        ->default('now')
                        ->required()
                        ->live()
                        ->columnSpanFull(),

                    DateTimePicker::make('publish_at')
                        ->label(__('admin.announcements.fields.publish_at'))
                        ->seconds(false)
                        ->native(false)
                        ->minDate(now())
                        // Only meaningful for a scheduled notice; required there, because a
                        // "scheduled" row with no time never leaves the sweep's candidate set.
                        ->visible(fn ($get) => $get('delivery') === 'schedule')
                        ->required(fn ($get) => $get('delivery') === 'schedule')
                        ->helperText(__('admin.announcements.fields.publish_at_hint')),

                    DateTimePicker::make('expires_at')
                        ->label(__('admin.announcements.fields.expires_at'))
                        ->seconds(false)
                        ->native(false)
                        ->helperText(__('admin.announcements.fields.expires_at_hint')),

                    Toggle::make('is_pinned')
                        ->label(__('admin.announcements.fields.is_pinned'))
                        ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.announcement_pinned'))
                        ->default(false),
                ]),
        ]);
    }
}
