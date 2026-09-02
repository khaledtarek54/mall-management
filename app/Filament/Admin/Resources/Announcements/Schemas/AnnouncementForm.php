<?php

namespace App\Filament\Admin\Resources\Announcements\Schemas;

use App\Models\Announcement;
use App\Services\SendAnnouncementAction;
use App\Support\Filament\PropertyField;
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
                    PropertyField::make()
                        ->label(__('admin.announcements.fields.property'))
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
                        // **A WINDOW THAT IS ALREADY SHUT SENDS EVERY TENANT TO A 404.** The blast
                        // goes out — push, bell, `announcement_recipients` — and the portal's own
                        // scope then hides the notice, so the deep link lands on nothing. And there
                        // is no repair: a sent notice is evidence and `isEditable()` refuses it.
                        //
                        // Bounded by the notice's own start, not by `now()`: a SCHEDULED notice
                        // published next Tuesday may legitimately expire the Wednesday after, which
                        // a `minDate(now())` would allow and a `after(publish_at)` gets right. The
                        // fallback covers "send immediately", where the start is the moment of
                        // sending.
                        // `max(publish_at, now())`, not `?:` — `publish_at` is only VISIBLE for a
                        // scheduled notice, but its state survives a switch back to "Send now", and
                        // a scheduled one whose time has slipped into the past would otherwise let
                        // an already-shut window through the form and leave the refusal to the
                        // service.
                        ->minDate(fn ($get) => self::windowOpensAt($get('publish_at')))
                        ->after(fn ($get) => self::windowOpensAt($get('publish_at')))
                        ->helperText(__('admin.announcements.fields.expires_at_hint')),

                    Toggle::make('is_pinned')
                        ->label(__('admin.announcements.fields.is_pinned'))
                        ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.announcement_pinned'))
                        ->default(false),
                ]),
        ]);
    }

    /**
     * The earliest a notice's window can close: its own start, or now, whichever is later.
     *
     * A stale `publish_at` — hidden by a switch back to "Send now", or scheduled for a time that has
     * since passed — must not widen the bound below today.
     */
    private static function windowOpensAt(mixed $publishAt): \Carbon\CarbonInterface
    {
        $now = \Carbon\CarbonImmutable::now();

        if (blank($publishAt)) {
            return $now;
        }

        $starts = rescue(fn () => \Carbon\CarbonImmutable::parse($publishAt), null, false);

        return $starts !== null && $starts->greaterThan($now) ? $starts : $now;
    }
}
