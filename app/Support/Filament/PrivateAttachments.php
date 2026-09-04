<?php

namespace App\Support\Filament;

use Filament\Infolists\Components\TextEntry;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * **A file the tenant sent us, shown back to the tenant who sent it.**
 *
 * Both things a retailer can upload from the portal were WRITE-ONLY. `TenantRequestForm` collects
 * up to five images or PDFs and `TenantSalesDeclarationForm` collects the sales report; neither
 * portal View screen offered any way to read one back — the request infolist did not mention them
 * at all, and the declaration infolist rendered a count badge ("2 files") with nothing behind it.
 * So a tenant asked "did my report reach you?" could not answer it themselves, and neither could
 * they check WHICH file they had attached to a dispute. Neither portal resource has an Edit page,
 * so there was no other surface either.
 *
 * **The mobile API has answered this since it shipped** — `TenantRequestResource` and
 * `TenantSalesDeclarationResource` both return `attachments[]` with a name, a mime type, a size and
 * an authenticated URL — and CLAUDE.md's rule for that pair is explicit: *the portal and `/api/v1`
 * are the same surface with different renderers — fix both or neither.*
 *
 * **The link is a SHORT-LIVED SIGNED one, not a path.** Every collection here is on the `local`
 * disk (the media-privacy invariant: private unless it is branding), which `config/filesystems.php`
 * serves only behind a valid signature. `Media::getTemporaryUrl()` mints exactly that, at the same
 * expiry Filament's own uploader uses — so the panel and the portal hand out links of the same
 * lifetime rather than two policies for one file. A driver that cannot mint one falls back to the
 * plain URL and, failing that, to naming the file with no link: losing the FILENAME is worse than
 * losing the link, because the filename is what tells the tenant their upload arrived.
 *
 * It is deliberately not an authorization layer. The screens that use it are already scoped to the
 * signed-in tenant (`TenantRequestResource::getEloquentQuery()` narrows on `Portal::tenantId()`,
 * the declaration one narrows through its lease), so a record another tenant owns is a 404 long
 * before an attachment is listed.
 */
final class PrivateAttachments
{
    /**
     * A read-only list of one media collection's files, each linked.
     *
     * @param  string  $collection  the spatie collection name, e.g. `attachments`
     * @param  string  $label  an EXISTING `admin.fields.*` label — this adds no vocabulary of its own
     */
    public static function entry(string $collection, string $label): TextEntry
    {
        return TextEntry::make("{$collection}_files")
            ->label($label)
            ->state(fn (mixed $record): array => $record instanceof HasMedia
                ? self::files($record, $collection)
                : [])
            // `$state` is ONE item of the list here, not the list — see TextEntry::toEmbeddedHtml(),
            // which wraps the state and formats each element.
            ->formatStateUsing(fn (array $state): string => $state['name'])
            // The parameter MUST be named `$state`: Filament decides a URL is per-ITEM by reflecting
            // for that exact name (`CanOpenUrl::hasStateBasedUrls()`), and a closure taking `$item`
            // silently becomes ONE url for the whole list — every file linking to the first one.
            ->url(fn (array $state): ?string => $state['url'])
            ->openUrlInNewTab()
            ->listWithLineBreaks()
            ->bulleted()
            // An em dash rather than an empty cell: "nothing attached" and "this screen forgot to
            // ask" look identical otherwise, which is the state this class exists to end.
            ->placeholder('—')
            ->columnSpanFull();
    }

    /**
     * @return list<array{name: string, url: string|null}>
     */
    private static function files(HasMedia $record, string $collection): array
    {
        return $record->getMedia($collection)
            ->map(fn (Media $media): array => [
                'name' => (string) $media->file_name,
                'url' => self::url($media),
            ])
            ->values()
            ->all();
    }

    private static function url(Media $media): ?string
    {
        $expiry = now()
            ->addMinutes((int) config('filament.temporary_file_url_expiry_minutes', 30))
            ->endOfHour();

        try {
            return $media->getTemporaryUrl($expiry);
        } catch (Throwable) {
            // The driver cannot sign a URL. Filament's own SpatieMediaLibraryFileUpload swallows the
            // same throw for the same reason; a private disk with `serve => false` is a supported
            // deployment and must not take the whole page down.
        }

        try {
            return $media->getUrl();
        } catch (Throwable) {
            return null;
        }
    }
}
