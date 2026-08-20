<?php

namespace App\Filament\Admin\Resources\JournalEntries\Pages;

use App\Filament\Admin\Resources\JournalEntries\JournalEntryResource;
use App\Services\Accounting\JournalPostingService;
use App\Support\Filament\RefreshesRecordState;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

class EditJournalEntry extends EditRecord
{
    use RefreshesRecordState;

    /**
     * Posting and voiding are service decisions; the form only reports them.
     *
     * @return array<int, string>
     */
    protected function derivedStatePaths(): array
    {
        return ['status'];
    }

    protected static string $resource = JournalEntryResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Block re-homing the draft into a property outside the user's visible set.
        JournalEntryResource::assertAssetInScope($data['asset_id'] ?? $this->record->asset_id);

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('post')
                ->label(__('admin.actions.post_journal_entry'))
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn () => $this->record->status === 'draft'
                    && Auth::user()?->can('journal_entries.post'))
                ->authorize(fn () => Auth::user()?->can('journal_entries.post') ?? false)
                ->requiresConfirmation()
                ->modalDescription(__('admin.actions.post_journal_entry_confirm'))
                ->action(function (): void {
                    // Persist any unsaved edits to the draft first (suppress the
                    // generic "Saved" toast — the Post notification follows).
                    $this->save(shouldRedirect: false, shouldSendSavedNotification: false);

                    try {
                        app(JournalPostingService::class)->postDraft($this->record->refresh());
                        $this->refreshFormData(['status']);
                        Notification::make()
                            ->title(__('admin.notifications.journal_entry_posted'))
                            ->body($this->record->number)
                            ->success()
                            ->send();
                    } catch (\DomainException $e) {
                        Notification::make()
                            ->title(__('admin.notifications.journal_entry_post_failed'))
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            Action::make('void')
                ->label(__('admin.actions.void_journal_entry'))
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('danger')
                ->visible(fn () => $this->record->status === 'posted'
                    && Auth::user()?->can('journal_entries.void'))
                ->authorize(fn () => Auth::user()?->can('journal_entries.void') ?? false)
                ->requiresConfirmation()
                ->modalDescription(__('admin.actions.void_journal_entry_confirm'))
                ->schema([
                    Textarea::make('reason')
                        ->label(__('admin.fields.void_reason'))
                        ->rows(2),
                ])
                ->action(function (array $data): void {
                    try {
                        $reversal = app(JournalPostingService::class)->void($this->record, $data['reason'] ?? null);
                        $this->refreshFormData(['status']);
                        Notification::make()
                            ->title(__('admin.notifications.journal_entry_voided'))
                            ->body(__('admin.notifications.journal_entry_voided_body', ['number' => $reversal->number]))
                            ->success()
                            ->send();
                    } catch (\DomainException $e) {
                        Notification::make()
                            ->title($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

        ];
    }

    /** Block the Save action entirely once the entry is posted/void (immutable). */
    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()
            ->visible(fn () => $this->record->status === 'draft');
    }
}
