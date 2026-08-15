<?php

namespace App\Filament\Portal\Resources\Announcements\Schemas;

use App\Models\Announcement;
use Filament\Infolists\Components\SpatieMediaLibraryImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * The notice, in full, as the tenant reads it.
 *
 * **Rendered in the reader's language, not the sender's.** `titleFor()`/`bodyFor()` pick from the
 * two stored columns against the ambient locale and fall back to whichever the operator actually
 * wrote — so an operator who typed only English still gets read, and one who wrote both is read
 * correctly by everyone. The mobile API ships both columns and lets the client choose; a server-
 * rendered page has no client to defer to, so it resolves here.
 */
class AnnouncementInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()
                ->icon(Heroicon::OutlinedMegaphone)
                ->schema([
                    TextEntry::make('category')
                        ->label(__('admin.announcements.fields.category'))
                        ->badge()
                        ->formatStateUsing(fn ($state) => __("admin.announcements.categories.{$state}"))
                        ->color(fn ($state) => $state === Announcement::CATEGORY_EMERGENCY ? 'danger' : 'gray'),

                    TextEntry::make('title')
                        ->label(__('admin.announcements.fields.title'))
                        ->weight('bold')
                        ->size('lg')
                        ->state(fn (Announcement $record) => $record->titleFor()),

                    SpatieMediaLibraryImageEntry::make('hero')
                        ->label(__('admin.announcements.fields.hero'))
                        ->collection(Announcement::HERO_COLLECTION)
                        ->hiddenLabel()
                        ->visible(fn (Announcement $record) => $record->getFirstMedia(Announcement::HERO_COLLECTION) !== null)
                        ->columnSpanFull(),

                    TextEntry::make('body')
                        ->label(__('admin.announcements.fields.body'))
                        ->state(fn (Announcement $record) => $record->bodyFor())
                        ->columnSpanFull(),

                    TextEntry::make('sent_at')
                        ->label(__('admin.announcements.fields.sent_at'))
                        ->dateTime('d/m/Y H:i'),

                    TextEntry::make('expires_at')
                        ->label(__('admin.announcements.fields.expires_at'))
                        ->dateTime('d/m/Y')
                        ->placeholder(__('admin.announcements.no_expiry')),
                ]),
        ]);
    }
}
