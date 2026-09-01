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
 * (`EvidenceAppendsAndNeverReplacesTest` pins both doors, and goes red if either reverts.)
 */
class EvidenceUpload
{
    public static function make(string $name = 'evidence'): SpatieMediaLibraryFileUpload
    {
        return SpatieMediaLibraryFileUpload::make($name)
            // A default label, because a component with none is humanised into English by Filament —
            // and `TranslationKeyConformanceTest` reads the source file, so the factory cannot rely
            // on its two call sites each setting one. They still override it: the contractor and the
            // operator are told different things about the same field.
            ->label(__('admin.facility.fields.evidence'))
            ->collection($name)
            ->multiple()
            ->image()
            // Kept for what it genuinely does: a second drop onto the widget adds to the list rather
            // than replacing it, which is the right client behaviour. The line below is what makes
            // the same promise true of the database.
            ->appendFiles()
            ->saveRelationshipsUsing(
                static fn (SpatieMediaLibraryFileUpload $component): mixed => $component->saveUploadedFiles()
            );
    }
}
