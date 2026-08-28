<?php

namespace App\Filament\Actions;

use App\Support\LedgerTrail;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Illuminate\Database\Eloquent\Model;

/**
 * "Ledger" — what this document did to the books, on the document itself.
 *
 * **A factory rather than a panel copied per resource**, for the same reason as
 * {@see PostMonthAction}: one behaviour shared by every money document, written out per table,
 * drifts. A different label here, a missing permission there, and the invoice screen ends up
 * telling a different story from the vendor-bill screen about the same mechanism.
 *
 * Usage: `LedgerEntryAction::make()` in a table's `recordActions()`.
 *
 * Read-only — `modalSubmitAction(false)` and no `->action()`, so there is nothing to authorize at
 * dispatch. It is still gated in `visible()` on `general_ledger.view`, because the debit/credit
 * detail of an entry is exactly what the GL permission exists to control: a leasing user can see
 * their invoice without seeing which accounts it moved.
 */
class LedgerEntryAction
{
    public static function make(string $permission = 'general_ledger.view'): Action
    {
        return Action::make('ledgerEntry')
            ->label(__('admin.actions.ledger_entry'))
            ->icon('heroicon-o-book-open')
            ->color('gray')
            ->modalHeading(__('admin.actions.ledger_entry_heading'))
            ->modalSubmitAction(false)
            ->visible(fn (): bool => auth()->user()?->can($permission) ?? false)
            ->authorize(fn (): bool => auth()->user()?->can($permission) ?? false)
            ->schema(fn (Model $record) => self::entries($record));
    }

    /** @return array<int, TextEntry> */
    private static function entries(Model $record): array
    {
        $trail = LedgerTrail::for($record);
        $entry = $trail['entry'];

        $status = match (true) {
            ! $trail['posts'] => __('admin.ledger_trail.does_not_post'),
            $entry === null => __('admin.ledger_trail.not_posted'),
            $entry->status === 'posted' => __('admin.ledger_trail.posted'),
            $entry->status === 'void' => __('admin.ledger_trail.reversed'),
            default => $entry->status,
        };

        $schema = [
            TextEntry::make('ledger_status')
                ->label(__('admin.ledger_trail.state'))
                ->state($status)
                ->badge()
                ->color(match (true) {
                    $entry?->status === 'posted' => 'success',
                    $entry?->status === 'void' => 'warning',
                    default => 'gray',
                }),
        ];

        if ($entry) {
            $schema[] = TextEntry::make('ledger_number')
                ->label(__('admin.ledger_trail.entry'))
                ->state($entry->number.'  ·  '.$entry->entry_date->format('d/m/Y'))
                ->helperText($trail['post_month']
                    ? __('admin.ledger_trail.post_month_note', ['month' => $trail['post_month']->format('m/Y')])
                    : null);

            $schema[] = TextEntry::make('ledger_property')
                ->label(__('admin.fields.property'))
                ->state($entry->asset?->getAttribute('name') ?? __('admin.fields.property_consolidated'));

            $schema[] = TextEntry::make('ledger_lines')
                ->label(__('admin.ledger_trail.lines'))
                ->state(implode("\n", LedgerTrail::lineRows($entry)) ?: '—');
        }

        // The chain. This is the part that was invisible: an edit to a posted document voids its
        // entry and posts a new one, and until now nothing said so anywhere an operator would look.
        if ($trail['reversal']) {
            $schema[] = TextEntry::make('ledger_reversal')
                ->label(__('admin.ledger_trail.reversed_by'))
                ->state($trail['reversal']->number.'  ·  '.$trail['reversal']->entry_date->format('d/m/Y'))
                ->helperText($trail['reversal']->displayDescription())
                ->color('warning');
        }

        if ($trail['superseded_by']) {
            $schema[] = TextEntry::make('ledger_superseded_by')
                ->label(__('admin.ledger_trail.replaced_by'))
                ->state($trail['superseded_by']->number.'  ·  '.$trail['superseded_by']->entry_date->format('d/m/Y'))
                ->color('success');
        }

        if (count($trail['history']) > 1) {
            $rows = [];
            foreach ($trail['history'] as $past) {
                $rows[] = $past->number.' · '.$past->entry_date->format('d/m/Y')
                    .' · '.__("admin.statuses.journal_entry.{$past->status}");
            }

            $schema[] = TextEntry::make('ledger_history')
                ->label(__('admin.ledger_trail.history'))
                ->state(implode("\n", $rows));
        }

        // Louder than the drift note, and deliberately before it: the books will be corrected
        // either way, but a figure the owner is already holding will stop matching them.
        if ($trail['restates_reported']) {
            $schema[] = TextEntry::make('ledger_restates')
                ->label(__('admin.ledger_trail.restates'))
                ->state(__('admin.ledger_trail.restates_body'))
                ->helperText($trail['reported_reason'])
                ->color('danger');
        }

        if ($trail['posts'] && $trail['drifted']) {
            // The same figures the save toast quotes, from the same `pendingRestatement()` call —
            // an operator who reads "reversed 12,400, re-posted 13,050" on the toast and then opens
            // this panel must not be shown a different story about the same pending change.
            $pending = $trail['pending'];
            $money = fn (?float $amount): string => 'EGP '.number_format((float) $amount, 2);

            $schema[] = TextEntry::make('ledger_drift')
                ->label(__('admin.ledger_trail.pending'))
                ->state(match (true) {
                    $pending === null => __('admin.ledger_trail.pending_changed'),
                    $pending['from'] === null => __('admin.notifications.ledger_will_post', ['amount' => $money($pending['to'])]),
                    $pending['to'] === null => __('admin.notifications.ledger_will_reverse', ['amount' => $money($pending['from'])]),
                    default => __('admin.notifications.ledger_will_repost', [
                        'from' => $money($pending['from']),
                        'to' => $money($pending['to']),
                    ]),
                })
                ->color('warning');
        }

        return $schema;
    }
}
