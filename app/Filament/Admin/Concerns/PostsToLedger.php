<?php

namespace App\Filament\Admin\Concerns;

use App\Models\SystemSetting;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;

/**
 * Shared "Post to GL now" header action + "Ledger last synced" subheading for the
 * accounting screens. Posting to the general ledger runs automatically on a daily
 * schedule (accounting:sync-ledger); this lets the accountant force it on demand and
 * see how fresh the books are — without ever touching the CLI.
 */
trait PostsToLedger
{
    protected function postToLedgerAction(): Action
    {
        return Action::make('post_to_ledger')
            ->label(__('admin.actions.post_to_ledger'))
            ->icon('heroicon-o-arrow-path')
            ->color('primary')
            ->requiresConfirmation()
            ->modalDescription(__('admin.actions.post_to_ledger_confirm'))
            ->visible(fn () => Auth::user()?->can('journal_entries.post') ?? false)
            ->authorize(fn () => Auth::user()?->can('journal_entries.post') ?? false)
            ->action(function () {
                // Windowed run (recent changes), --since so any un-postable doc surfaces
                // via a non-zero exit rather than silently. Full re-syncs stay a CLI op.
                $exit = Artisan::call('accounting:sync-ledger', [
                    '--since' => now()->subDays(2)->toDateString(),
                ]);

                if ($exit === 0) {
                    Notification::make()->title(__('admin.notifications.ledger_posted'))->success()->send();
                } else {
                    Notification::make()
                        ->title(__('admin.notifications.ledger_posted_with_issues'))
                        ->warning()
                        ->send();
                }
            });
    }

    protected function ledgerLastSyncedSubheading(): ?string
    {
        $ts = SystemSetting::get('ledger_last_synced_at');
        if (! $ts) {
            return __('admin.reports.ledger_never_synced');
        }

        // Degrade to the never-synced copy rather than 500 the page if the stored
        // value is ever unparseable (defensive — the command only writes ISO8601).
        try {
            $when = Carbon::parse($ts)->diffForHumans();
        } catch (\Throwable) {
            return __('admin.reports.ledger_never_synced');
        }

        return __('admin.reports.ledger_last_synced', ['time' => $when]);
    }
}
