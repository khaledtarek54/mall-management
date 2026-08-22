<?php

namespace App\Support\Filament;

use App\Support\DeletionPolicy;
use Filament\Actions\ForceDeleteAction;

/**
 * Filament's `ForceDeleteAction`, gated — and it needed it more than `DeleteAction` did.
 *
 * The same policy hole ({@see AnnouncingDeleteAction} explains it) left this one open on thirteen
 * Edit pages, and the argument that saved `RestoreAction` does not transfer: `canRestore()` is the
 * module's `.edit` right, which the Edit page's own mount already required, but `canForceDelete()`
 * is **super_admin only** — a strictly higher bar than the page. Proven before it was fixed: a plain
 * `manager` permanently destroyed a soft-deleted Tenant and a soft-deleted Lease.
 *
 * **And the usual backstop deliberately stands down here.**
 * `RefusesDeletionWhenReferenced` waives its `blockedBy` check when a trashed row is force-deleted,
 * so the FKs decide instead — and they disagree: `invoices.lease_id` is `restrictOnDelete` (loud),
 * while `charges.lease_id` and `tenant_sales_declarations.lease_id` are `cascadeOnDelete`. Force
 * deleting a soft-deleted lease therefore takes its contracted rent ladder and its declared sales
 * with it, silently. That waiver is defensible only if the actor really is super_admin, which is
 * exactly what nothing was checking.
 *
 * The floor for a child row with no resource is FAIL-CLOSED here, unlike delete: there is no
 * legitimate force-delete in a relation manager anywhere in this panel, and the operation destroys
 * the row outright.
 */
class AnnouncingForceDeleteAction extends ForceDeleteAction
{
    use AnnouncesRecordChange;

    public function isAuthorized(): bool
    {
        if (! parent::isAuthorized()) {
            return false;
        }

        $record = $this->getRecord();

        if ($record === null) {
            return false;
        }

        return ResourceAbility::may('canForceDelete', $this->getLivewire(), $record)
            ?? DeletionPolicy::resourceMayDelete($record::class);
    }

    protected function assertActionAuthorized(): void
    {
        abort_unless($this->isAuthorized(), 403);
    }
}
