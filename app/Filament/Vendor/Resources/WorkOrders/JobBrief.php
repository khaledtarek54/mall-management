<?php

namespace App\Filament\Vendor\Resources\WorkOrders;

use App\Models\FacilityWorkOrder;
use App\Models\FacilityWorkOrderComment;
use App\Models\VendorContact;
use App\Models\WorkOrderProposal;
use App\Support\Filament\VendorScope;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;

/**
 * What the contractor is allowed to READ about a job — the half the portal shipped without.
 *
 * The portal gave a contractor four verbs and no way to see anything. Two consequences, both of
 * them the point of having a portal at all:
 *
 *  - **The thread was write-only.** A contractor could post an update and could never read one.
 *    `FacilityWorkOrderComment::is_internal` exists precisely so an operator can write something the
 *    contractor must not see — which means every PUBLIC comment was written for a contractor who had
 *    no surface to read it on. The operator ticked *"share with the contractor"* and it reached
 *    nobody; the reply came back on WhatsApp, which is the behaviour the portal replaces.
 *  - **The quote loop was one-way.** `nte_amount` is the not-to-exceed the operator sets, and
 *    exceeding it is the whole reason a contractor is asked for a price — invisible, so the trigger
 *    for the act was hidden from the person expected to perform it. And the DECISION never came
 *    back: a quote was approved or rejected, `decision_reason` recorded, and the contractor learnt
 *    which by being dispatched or not.
 *
 * A modal off the row rather than a View page, which is this project's idiom for a read that hangs
 * off an act. There is no `canView()` layer underneath it either: {@see VendorScope::assertOwned()}
 * is re-asked here, because the list is narrowed and the button is hidden and neither is a gate —
 * the Livewire payload still carries an id. **404, never 403**, for the reason the whole portal
 * uses: a 403 confirms the job exists.
 *
 * **Only PUBLIC comments, and that is a hard rule rather than a default.** An internal note is the
 * operator's tool for writing what the contractor must not read; a filter written here as `where`
 * on the loaded collection would be a second definition of that, so it narrows the QUERY.
 *
 * **{@see factsOf()} is the test seam**, for the reason `PdfDocument::html()` is: a schema component
 * outside a mounted container throws on `$container` before it answers anything — the trap
 * `getHelperText()` and `Repeater::getLabel()` set, and the reason two of this project's own gates
 * were once measuring nothing — and the modal's rendered HTML is assembled lazily by Filament, so
 * neither is assertable. What CAN be wrong here is which facts a contractor is told, and that is one
 * array. The modal is still mounted through the real page in the same test file, because a schema is
 * built ON MOUNT and a page can render perfectly and fatal the moment somebody clicks.
 */
class JobBrief
{
    /**
     * @return array<int, mixed>
     */
    public static function of(FacilityWorkOrder $record): array
    {
        // The gate, not a convenience: this modal is composed from an action whose payload names a
        // record id, so it re-asks the one question the portal ever asks.
        VendorScope::assertOwned($record);

        $facts = self::factsOf($record);

        return [
            Section::make(__('vendor.jobs.brief.what'))
                ->columns(2)
                ->schema([
                    TextEntry::make('reference')
                        ->label(__('vendor.jobs.reference'))
                        ->state(fn (): string => $facts['reference']),

                    TextEntry::make('trade')
                        ->label(__('vendor.jobs.brief.trade'))
                        ->state(fn (): string => $facts['trade']),

                    TextEntry::make('title')
                        ->label(__('vendor.jobs.title'))
                        ->columnSpanFull()
                        ->state(fn (): string => $facts['title']),

                    TextEntry::make('description')
                        ->label(__('vendor.jobs.brief.description'))
                        ->columnSpanFull()
                        ->state(fn (): string => $facts['description']),

                    TextEntry::make('where')
                        ->label(__('vendor.jobs.brief.where'))
                        // The property, the shop and the zone: a contractor arriving at the mall
                        // needs all three, and only one of them was on the list.
                        ->state(fn (): string => $facts['where']),

                    TextEntry::make('scheduled_for')
                        ->label(__('vendor.jobs.scheduled_for'))
                        ->state(fn (): string => $facts['scheduled_for']),
                ]),

            // **THE NOT-TO-EXCEED.** Rendered only when one is set, because a row saying "—" reads
            // as "no limit stated" on exactly the field where silence must not be read as freedom.
            Section::make(__('vendor.jobs.brief.limit'))
                ->visible(fn (): bool => $record->nte_amount !== null)
                ->schema([
                    TextEntry::make('nte_amount')
                        ->label(__('vendor.jobs.brief.nte'))
                        ->state(fn (): string => $facts['nte'])
                        ->helperText(__('vendor.jobs.brief.nte_help')),
                ]),

            // **THE THREAD, PUBLIC HALF ONLY.** Narrowed in the query, never on the collection: an
            // internal note is the operator's tool for writing what this reader must not see.
            Section::make(__('vendor.jobs.brief.thread'))
                ->schema([
                    TextEntry::make('thread')
                        ->hiddenLabel()
                        ->columnSpanFull()
                        // **A LIST, NOT A NEWLINE-JOINED STRING.** A single-item `TextEntry` renders
                        // as `e($state)` inside a bare div, and neither Filament's stylesheet nor
                        // this theme sets `white-space` on it — so `\n` COLLAPSES and the whole
                        // conversation reads as one run-on paragraph, byline running into body and
                        // message into message. `listWithLineBreaks()` is the branch that emits an
                        // element per item. `markdown()` would also break the lines and would mangle
                        // any operator text containing `#`, `*` or `_`; `html()` is stored XSS on
                        // text typed by a contractor.
                        ->state(fn (): array => $facts['thread'])
                        ->listWithLineBreaks()
                        ->placeholder(__('vendor.jobs.brief.thread_empty')),
                ]),

            // **THE DECISION, WHICH IS THE HALF THAT NEVER CAME BACK.**
            Section::make(__('vendor.jobs.brief.quotes'))
                ->visible(fn (): bool => self::ownQuotes($record)->exists())
                ->schema([
                    TextEntry::make('quotes')
                        ->hiddenLabel()
                        ->columnSpanFull()
                        ->state(fn (): array => $facts['quotes'])
                        ->listWithLineBreaks(),
                ]),
        ];
    }

    /**
     * Everything the contractor is told about this job, resolved once — and the test seam.
     *
     * @return array{reference: string, trade: string, title: string, description: string,
     *               where: string, scheduled_for: string, nte: string,
     *               thread: array<int, string>, quotes: array<int, string>}
     */
    public static function factsOf(FacilityWorkOrder $record): array
    {
        VendorScope::assertOwned($record);

        return [
            'reference' => $record->reference ?? '—',
            'trade' => $record->trade?->name ?? '—',
            'title' => $record->title ?? '—',
            'description' => $record->description ?? '—',
            // The property, the shop and the zone: a contractor arriving at the mall needs all
            // three, and only one of them was ever on the list.
            'where' => collect([
                $record->asset?->name,
                $record->unit?->code,
                $record->area?->name,
            ])->filter()->implode(' · ') ?: '—',
            'scheduled_for' => $record->scheduled_for?->format('d/m/Y') ?? '—',
            'nte' => $record->nte_amount === null
                ? '—'
                : 'EGP '.number_format((float) $record->nte_amount, 2),
            // Lists, for the reason the entries above give: a newline inside one `TextEntry` is
            // collapsed by the browser, so each message and each quote is its own item.
            'thread' => self::thread($record),
            'quotes' => self::quotes($record),
        ];
    }

    /**
     * This contractor's own quotes on this job — one definition, read by the list and by the
     * section's own `visible()`, so a section cannot appear over an empty list.
     *
     * @return HasMany<WorkOrderProposal, FacilityWorkOrder>
     */
    private static function ownQuotes(FacilityWorkOrder $record): HasMany
    {
        // A null contact (nobody signed in) matches NOTHING, never everything — the rule
        // `VendorScope` states for the panel query, applied here for the same reason.
        return $record->proposals()
            ->where('vendor_id', VendorScope::contact()?->vendor_id ?? 0)
            ->orderBy('id');
    }

    /**
     * The public conversation, oldest first — the order it was held in.
     *
     * @return array<int, string>
     */
    private static function thread(FacilityWorkOrder $record): array
    {
        return $record->comments()
            ->where('is_internal', false)
            // The author is a MORPH and a job's thread is unbounded, so an unloaded relation is a
            // query per message. The operator's own thread eager-loads it for the same reason.
            ->with('author')
            ->get()
            ->map(fn (FacilityWorkOrderComment $c): string => trim(sprintf(
                '%s — %s: %s',
                $c->created_at?->format('d/m/Y H:i') ?? '',
                self::authorName($c),
                (string) $c->body,
            )))
            ->values()
            ->all();
    }

    /**
     * Who said it — and WHICH SIDE they are on.
     *
     * The author is a MORPH, an operator or one of this company's own contacts, so both facts have
     * to be asked of the row rather than assumed. A bare *"Hani"* leaves a contractor unable to tell
     * whether that came from their own colleague or from a mall employee, which is the one thing a
     * byline on a two-party thread exists to say; the operator's own thread already labels both
     * sides, so this is parity rather than a new idea.
     *
     * **Neither model soft-deletes, so a null author is a HARD-deleted row**, and it may have been
     * either party — the first draft signed it *"the operator"*, which attributes a contractor's own
     * message to the mall. It now reads as unknown, and only the side is dropped.
     */
    private static function authorName(FacilityWorkOrderComment $comment): string
    {
        $author = $comment->author;

        if ($author === null) {
            return __('vendor.jobs.brief.from_unknown');
        }

        $name = (string) ($author->name ?? __('vendor.jobs.brief.from_unknown'));

        $side = $author instanceof VendorContact
            ? __('vendor.jobs.brief.from_us')
            : __('vendor.jobs.brief.from_operator');

        return $name.' ('.$side.')';
    }

    /**
     * Every quote **this** contractor has sent, and what the operator decided.
     *
     * **The `vendor_id` clause is a gate, not a tidy-up.** A job legitimately carries quotes from
     * more than one contractor — `WorkOrderProposalService::submit()` defaults the vendor to the one
     * on the job and lets the operator state another (*"a quote from somebody else is legitimate"*),
     * the admin relation manager offers a free vendor picker, and re-dispatching a job leaves the
     * previous contractor's decided quotes behind. Without the clause the LOSING bidder reads the
     * winner's number and the operator's `decision_reason` — which is exactly where competitive
     * information lives, as this file's own fixture shows: *"Second quote came in lower — going with
     * the other contractor."* `VendorScope`'s first paragraph names this: not their vendor record,
     * not the property, **not other contractors' work**.
     *
     * @return array<int, string>
     */
    private static function quotes(FacilityWorkOrder $record): array
    {
        return self::ownQuotes($record)
            ->get()
            ->map(function (WorkOrderProposal $p): string {
                $line = sprintf(
                    '%s — EGP %s — %s',
                    $p->submitted_at?->format('d/m/Y') ?? '',
                    number_format((float) $p->total_amount, 2),
                    __('vendor.jobs.brief.quote_status.'.$p->status),
                );

                // The REASON, which is the only part a contractor can act on: a rejection with no
                // reason is a decision they cannot answer, and re-quoting blind is what the whole
                // loop exists to avoid. On the same LINE, because a newline inside one list item is
                // collapsed exactly as it was inside the whole blob.
                if (filled($p->decision_reason)) {
                    $line .= ' — '.$p->decision_reason;
                }

                return $line;
            })
            ->values()
            ->all();
    }
}
