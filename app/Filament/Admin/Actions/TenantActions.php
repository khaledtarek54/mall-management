<?php

namespace App\Filament\Admin\Actions;

use App\Filament\Admin\Resources\Payments\PaymentResource;
use App\Filament\Admin\Resources\Violations\ViolationResource;
use App\Models\Tenant;
use App\Support\ResourceLink;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Illuminate\Support\Facades\Auth;

/**
 * **Everything you can DO to a tenant, defined once and composed onto BOTH of its pages.**
 *
 * THE RULE, taken from Yardi and from this repo's own benchmark of it
 * (`docs/benchmarks/yardi/08-yardi-ui-ux.md`): **an act belongs to the RECORD and appears by
 * PERMISSION — not by which page you happened to open, and never inside a tab.** Voyager has one
 * customer screen whose buttons are governed by function access; UX-01 refused to build a second
 * read-only lease surface for exactly this reason (*"the lease page already IS the hub, and a
 * second surface showing the same facts drifts from it"*), and UX-08 repeated it for the CAM pool.
 * A tab is for LISTING what is attached to the record.
 *
 * Three acts, and each was somewhere else for a different bad reason:
 *
 * - **`logCommunication`** was `CreateAction` inside the notes tab, which had to waive Filament's
 *   read-only-under-a-`ViewRecord` rule to be reachable at all.
 * - **`recordPayment`** and **`recordViolation`** were header buttons on the payments and
 *   violations TABS — `->url()` links into another resource's create form, which the read-only
 *   rule cannot see because a link is not an action. So a page whose whole claim is that it does
 *   not write offered *Record payment*.
 *
 * All three now sit in the record's header on `ViewTenant` **and** `EditTenant`, from one
 * definition, so the answer to *"can I record a receipt for this tenant"* is the operator's
 * permission and nothing else. The first version of this fix removed the two links instead, which
 * closed the reported defect and made the read-only page the one place you could not act from —
 * the opposite of the record-hub architecture the rest of the panel follows.
 *
 * **A tab's OWN CreateAction is not this and stays where it is** (portal users, documents, notes on
 * the Edit page): adding a contact to a company is that relationship's own row, and Yardi puts it
 * in the contacts tab too. What moved is the CROSS-RESOURCE act — a receipt and a violation are
 * their own documents with their own numbering, gates and books.
 *
 * **Composed as ONE spread (`...TenantActions::all()`), never act by act.** `Tests\Support\ActionStrips`
 * expands any `Registry::method()` call to every member of that registry, so three individual calls
 * would read as nine acts and `AnActIsDeclaredOnceConformanceTest` would report duplicates that do
 * not exist.
 *
 * The note FORM is shared rather than copied: the relation manager renders
 * {@see TenantActions::formComponents()} and so does the modal, so the fields an operator fills in
 * cannot depend on which surface they were standing on.
 */
class TenantActions
{
    /**
     * Composed onto `ViewTenant` and `EditTenant` as a single spread.
     *
     * Each act carries its own `visible()`/`->authorize()` pair, so a role simply does not see the
     * ones it may not run — the page does not decide, and the two pages therefore cannot offer
     * different answers to the same operator.
     *
     * @return array<int, Action>
     */
    public static function all(): array
    {
        return [
            static::logCommunication(),
            static::recordPayment(),
            static::recordViolation(),
        ];
    }

    /**
     * Record a receipt against this tenant.
     *
     * A LINK, not a modal, and deliberately so: it opens the real payment form with the tenant
     * carried across rather than a second, thinner one. That form owns the posting-date guard, the
     * property scope, the over-allocation backstop and the orphaned-receipt refusal — none of which
     * a convenience modal here would inherit.
     *
     * `for_tenant`, never `tenant`: the latter is Filament's tenancy ROUTE parameter, so it would
     * put the tenant's id in the path where the mall's slug belongs and the page would 404.
     * {@see ResourceLink::create()} refuses that collision rather than describing it.
     */
    public static function recordPayment(): Action
    {
        return Action::make('recordPayment')
            ->label(__('admin.collections.record_payment'))
            ->icon('heroicon-o-banknotes')
            ->color('gray')
            ->visible(fn (): bool => Auth::user()?->can('payments.create') ?? false)
            ->authorize(fn (): bool => Auth::user()?->can('payments.create') ?? false)
            ->url(fn (Tenant $record): string => ResourceLink::create(PaymentResource::class, [
                'for_tenant' => $record->getKey(),
            ]));
    }

    /**
     * Record a violation against this tenant. Same shape and same reasoning as
     * {@see recordPayment()} — the violation form owns the fine, the category and the evidence.
     */
    public static function recordViolation(): Action
    {
        return Action::make('recordViolation')
            ->label(__('admin.actions.record_violation'))
            ->icon('heroicon-o-exclamation-triangle')
            ->color('gray')
            ->visible(fn (): bool => ViolationResource::canCreate())
            ->authorize(fn (): bool => ViolationResource::canCreate())
            ->url(fn (Tenant $record): string => ResourceLink::create(ViolationResource::class, [
                'for_tenant' => $record->getKey(),
            ]));
    }

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
