<?php

namespace App\Filament\Admin\Actions;

use App\Models\Tenant;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Illuminate\Support\Facades\Auth;

/**
 * **Logging a call on a tenant, defined once and reachable from both of the tenant's pages.**
 *
 * A READ-ONLY PAGE'S TABS OFFER NO WRITE BUTTONS, and the notes tab was the one exception in the
 * panel. `TenantNotesRelationManager` waived Filament's own rule — every relation manager under a
 * `ViewRecord` is read-only, and its Create/Edit/Delete are denied before their own gates are
 * consulted — by returning `isReadOnly(): false`, so `ViewTenant` rendered *Log communication*,
 * *Edit* and *Delete* inside a tab on a page whose whole claim is that it does not write.
 *
 * **The waiver was not arbitrary and the reason it existed is the reason this class does.**
 * Measured across all 14 roles: `customer_service` is the front desk and holds `tenants.view`,
 * `notes.view`, `notes.create` and the request rights — and **no `tenants.edit`**, which is the
 * load-bearing half. `ListTenants` opens for them on `tenants.view`; what `tenants.edit` gates is
 * `EditTenant`, so **`ViewTenant` is the only tenant screen carrying this tab that they can
 * reach**. Taking the tab's write buttons away without putting the act somewhere else would leave
 * a granted right reaching no screen, which is the {@see \App\Support\PermissionReach} failure in
 * its confusing direction: the role appears to hold the permission, the screen refuses, and nobody
 * can tell policy from bug.
 *
 * **So the act moves to the record's HEADER, where this panel already puts acts** — the list
 * FINDS, the record ACTS ({@see \App\Support\RowActionPolicy}) — and the tab goes back to
 * Filament's default. `ViewTenant` therefore reads as what it is: a page of tables you cannot
 * edit, with the things you may DO to the tenant in the header beside *Edit*.
 *
 * **`EditTenant` is deliberately NOT given this act.** Its notes tab is writable in the ordinary
 * way, and adding a second door to the same act on one screen is the duplicate
 * {@see \App\Support\Filament\RecordChanged} and `AnActIsDeclaredOnceConformanceTest` exist to
 * refuse. Two different PAGES each offering one way in is not that.
 *
 * The FORM is shared rather than copied: the relation manager renders
 * {@see TenantNoteActions::formComponents()} and so does the modal, so the fields an operator
 * fills in cannot depend on which page they happened to be standing on.
 */
class TenantNoteActions
{
    /**
     * The note form — one definition, rendered by the relation manager and by the modal.
     *
     * @return array<int, Component>
     */
    public static function formComponents(): array
    {
        return [
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
        ];
    }

    /**
     * Log a call, WhatsApp, e-mail, meeting or site visit against this tenant.
     *
     * Gated TWICE on one named predicate, the house rule: `visible()` shapes the UI and
     * `abort_unless` inside `action()` is the gate. `visible()` is not an authorization check —
     * it is a statement of intent that also happens to disable the action on the version we ship,
     * and an upstream release could change that for every such action at once.
     *
     * `notes.create` and nothing else: this act does not read or write the TENANT, so requiring
     * `tenants.edit` here would re-close the door it was written to open.
     */
    public static function logCommunication(): Action
    {
        return Action::make('logCommunication')
            ->label(__('admin.actions.log_communication'))
            ->modalHeading(__('admin.actions.log_communication'))
            ->icon('heroicon-o-chat-bubble-left-right')
            ->color('gray')
            ->visible(fn (): bool => static::mayLog())
            // Declared as well as checked in the closure, and the difference is real here: with no
            // `->authorize()` the container seam (`AuthorizedAction::call()`) has nothing to test —
            // `isAuthorized()` answers true when nothing was declared — so the only refusal left at
            // dispatch would be `isDisabled()` folding in `isHidden()`, which is an upstream
            // implementation detail a release could change for every such action at once.
            ->authorize(fn (): bool => static::mayLog())
            ->schema(static::formComponents())
            ->action(function (array $data, Tenant $record): void {
                abort_unless(static::mayLog(), 403);

                // `author_id` is stamped here, never taken from the payload: who made the call is
                // a fact about the session, not something the form may state.
                $record->notes()->create([
                    ...$data,
                    'author_id' => Auth::id(),
                ]);

                Notification::make()
                    ->success()
                    ->title(__('admin.actions.log_communication'))
                    ->send();
            });
    }

    /**
     * The one predicate the button and the gate both read, so they cannot drift.
     */
    protected static function mayLog(): bool
    {
        return Auth::user()?->can('notes.create') ?? false;
    }
}
