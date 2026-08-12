<?php

namespace App\Support;

use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

/**
 * From a posted journal entry back to the document that caused it.
 *
 * The ledger here is DERIVED — every entry names its `source_type`/`source_id` — but until now that
 * link was only on the journal-entries table. Read a general ledger or a trial balance and the
 * numbers were terminal: correct, and with no way to ask what they were made of. That is the single
 * biggest difference between this and the systems it is benchmarked against, where a statement line
 * opens its entries and an entry opens its document.
 *
 * **Resolved through Filament rather than a hand-kept map.** `Filament::getModelResource()` already
 * knows which resource owns a model, so a new posting source is linkable the day its resource
 * exists — no registry to update, and no registry to forget. The alternative was a `source_type =>
 * resource` array that would be wrong the first time somebody added a module and right about
 * nothing else.
 *
 * **A dead link is worse than a plain label.** Every failure — no resource, no edit page, a record
 * the operator may not view, a source that has been deleted — returns null, and the caller renders
 * text. An operator who clicks into a 403 learns that the system is broken; one who sees a label
 * learns what the line is.
 */
class SourceDocumentUrl
{
    /** The edit URL for a source document, or null when there is nowhere safe to send the user. */
    public static function for(?Model $source): ?string
    {
        if ($source === null) {
            return null;
        }

        $resource = Filament::getModelResource($source::class);

        if (! $resource || ! method_exists($resource, 'getUrl')) {
            return null;
        }

        // Authorisation, not decoration: a general ledger is visible to more roles than the
        // documents behind it, so a link must not offer a route into a record the operator is not
        // allowed to open. `canView` is the resource's own answer, which is the same one the
        // destination page will give.
        if (method_exists($resource, 'canView') && ! rescue(fn () => $resource::canView($source), false, false)) {
            return null;
        }

        return rescue(fn () => $resource::getUrl('edit', ['record' => $source]), null, false);
    }

    /**
     * The same, from the morph pair a report row carries.
     *
     * Reports select `source_type`/`source_id` as columns rather than hydrating the model, so this
     * resolves it — once per distinct document, memoized per request, because a general ledger of a
     * thousand lines is a thousand rows pointing at far fewer documents.
     */
    public static function forSource(?string $sourceType, int|string|null $sourceId): ?string
    {
        if (blank($sourceType) || blank($sourceId)) {
            return null;
        }

        $key = "atriom.source_url.{$sourceType}.{$sourceId}";

        if (app()->has($key)) {
            return app($key);
        }

        $class = Model::getActualClassNameForMorph($sourceType);
        $url = class_exists($class)
            ? self::for(rescue(fn () => $class::find($sourceId), null, false))
            : null;

        app()->instance($key, $url);

        return $url;
    }
}
