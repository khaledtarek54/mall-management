<?php

namespace App\Filament\Portal\Actions;

use App\Models\Lease;
use Filament\Actions\Action;

/**
 * What a tenant may DO to a lease from the portal — the lease list row and the lease page.
 *
 * One act today, and it was written twice (`ViewLease` header, `LeasesTable` row) before this
 * registry existed. See {@see InvoiceActions} for why a portal act is defined once and composed
 * onto both surfaces as a single spread.
 */
final class LeaseActions
{
    /** @return array<int, Action> */
    public static function all(): array
    {
        return [
            self::downloadDocument(),
        ];
    }

    /**
     * The signed lease document, newest upload.
     *
     * A download announces nothing and writes nothing; the portal's read-only rule for a
     * `ViewRecord` is untouched. Offered only where a document exists — an empty download is a
     * 404 wearing the look of a button.
     */
    public static function downloadDocument(): Action
    {
        return Action::make('downloadDocument')
            ->label(__('admin.portal.lease.download_document'))
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->visible(fn (Lease $record): bool => $record->getMedia(Lease::DOCUMENTS_COLLECTION)->isNotEmpty())
            ->action(function (Lease $record) {
                $media = $record->getMedia(Lease::DOCUMENTS_COLLECTION)->last();
                abort_if($media === null, 404);

                return $media->toResponse(request());
            });
    }
}
