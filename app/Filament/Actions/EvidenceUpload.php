<?php

namespace App\Filament\Actions;

use Filament\Forms\Components\SpatieMediaLibraryFileUpload;

/**
 * The evidence attachment on a work order — the ONE definition, for the two doors onto it.
 *
 * A work order's `evidence` collection is **append-only**. It is what
 * `SlaSettings::require_completion_evidence` gates the operator's completion on, so a photograph
 * that disappears takes with it the thing an earlier decision rested on — and there is no second
 * copy: the whole point of the vendor portal's evidence verb is that the photograph stops living in
 * somebody's WhatsApp.
 *
 * **`->appendFiles()` does not make a collection append-only, and that is the bug this exists to
 * close.** It is a `FileUpload` option about how the BROWSER widget behaves when a second file is
 * dropped onto a populated field. The server side is
 * `SpatieMediaLibraryFileUpload::saveRelationshipsUsing()`, which calls `deleteAbandonedFiles()`
 * first — and that deletes every medium in the collection whose uuid is absent from the component's
 * state. In a FORM the state was hydrated from the record, so "absent" really does mean the operator
 * removed it. In an ACTION MODAL nothing hydrates it: the schema opens empty, so every photograph
 * already on the job is abandoned by definition and is deleted the moment a new one is saved.
 *
 * Measured, before this class existed: a contractor uploaded `before.jpg`, came back the next day
 * and uploaded `after.jpg`, and the collection held `after.jpg` alone. Both call sites carried
 * `->appendFiles()` and a comment promising exactly the behaviour they did not have. The two doors
 * also erased each other — the operator's `attachEvidence` wiped what the contractor had sent, and
 * the contractor's upload wiped what the operator had attached.
 *
 * So the save is overridden to upload and **never** delete. Nothing in either modal is a way to
 * REMOVE evidence, which is the point: removing it is not one of the verbs, on either side. A
 * collection that may only grow needs no reconciliation against the submitted state, so skipping
 * `deleteAbandonedFiles()` is not a workaround here — it is the correct save for what this field is.
 *
 * The label and helper text stay with the caller: the contractor and the operator are told different
 * things about the same field, and that is right.
 *
 * **WHAT MAY BE FILED IS NOT one of the things a caller decides (SW-126).** It was: this factory
 * said `->image()` and capped nothing, while `FacilityWorkOrderForm` — a THIRD door onto the same
 * `evidence` collection, and the one this class never counted — accepted `application/pdf`, capped
 * each file at 10 MB and the batch at 10. So one collection had two answers to *"may I file the
 * signed permit?"*: yes on the work-order form, refused at the button actually labelled *Attach
 * evidence* and refused again at the contractor's own door, which is where a completion certificate,
 * a signed hot-work permit or a supplier's report arrives from. The shared field was also the only
 * one with no size cap at all, on a PRIVATE disk written to by an external contractor, leaving
 * Livewire's 12 MB temporary-upload default as the whole bound.
 *
 * {@see accepting()} is that one answer, and the work-order form composes it too, so the three doors
 * cannot drift. Removal is still NOT shared: the form hydrates from the record, so "absent means the
 * operator removed it" is true there and false in a modal that opens empty — which is the whole
 * reason for the save override below.
 *
 * (`EvidenceAppendsAndNeverReplacesTest` pins both doors and goes red if either reverts;
 * `EveryDoorOntoTheEvidenceCollectionTakesTheSameFileTest` pins what all three of them accept.)
 */
class EvidenceUpload
{
    /**
     * A photograph OR a document. Evidence is whatever settles the question later — a picture of the
     * repaired pump, and equally the signed permit or the completion certificate that came with it.
     *
     * @var array<int, string>
     */
    public const ACCEPTED_FILE_TYPES = ['image/*', 'application/pdf'];

    /** Kilobytes per file — the cap `FacilityWorkOrderForm` has always applied, now applied everywhere. */
    public const MAX_KILOBYTES = 10240;

    /** Files per upload. `maxFiles()` compiles to a `max:` rule on the array, so this is a real refusal. */
    public const MAX_FILES = 10;

    /**
     * What a piece of evidence may BE, applied to whichever field is asking for it.
     *
     * Takes the component rather than returning a configured one, because the three doors differ in
     * everything else — label, helper text, whether removal is possible, whether the file can be
     * opened from the panel — and only agree on this.
     */
    public static function accepting(SpatieMediaLibraryFileUpload $upload): SpatieMediaLibraryFileUpload
    {
        return $upload
            ->acceptedFileTypes(self::ACCEPTED_FILE_TYPES)
            ->maxSize(self::MAX_KILOBYTES)
            ->maxFiles(self::MAX_FILES);
    }

    public static function make(string $name = 'evidence'): SpatieMediaLibraryFileUpload
    {
        return self::accepting(SpatieMediaLibraryFileUpload::make($name))
            // A default label, because a component with none is humanised into English by Filament —
            // and `TranslationKeyConformanceTest` reads the source file, so the factory cannot rely
            // on its two call sites each setting one. They still override it: the contractor and the
            // operator are told different things about the same field.
            ->label(__('admin.facility.fields.evidence'))
            ->collection($name)
            ->multiple()
            // Kept for what it genuinely does: a second drop onto the widget adds to the list rather
            // than replacing it, which is the right client behaviour. The line below is what makes
            // the same promise true of the database.
            ->appendFiles()
            ->saveRelationshipsUsing(
                static fn (SpatieMediaLibraryFileUpload $component): mixed => $component->saveUploadedFiles()
            );
    }
}
