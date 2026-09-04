<?php

namespace App\Filament\Admin\Resources\BankStatements\RelationManagers;

use App\Models\BankMatch;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Services\Banking\ImportBankStatementService;
use App\Services\Banking\MatchBankStatementLineService;
use App\Support\Imports;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;

/**
 * The reconciliation workspace: the bank's lines, and what the books say about each.
 *
 * Importing and matching live together because they are one job — a statement you cannot match is
 * half a feature, and a matcher with nothing imported is the other half.
 *
 * **Nothing here posts.** Importing stores the bank's version; matching annotates two rows that
 * already exist. A bank charge with no book entry is recorded as an expense through the normal path,
 * with its posting-date and approval guards, and only then matched — which is the rule that keeps
 * this screen from becoming a back door into the ledger.
 */
class LinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.relation_managers.bank_statement_lines');
    }

    /** Matching changes no balance, so it rides on the same right as editing the statement. */
    private function canMatch(): bool
    {
        return Auth::user()?->can('bank_accounts.edit') ?? false;
    }

    public function table(Table $table): Table
    {
        $service = app(MatchBankStatementLineService::class);

        return $table
            ->searchable(false)
            ->modifyQueryUsing(fn ($query) => $query->with('matches.journalLine.entry'))
            ->columns([
                TextColumn::make('value_date')
                    ->label(__('admin.fields.transaction_date'))
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('description')
                    ->label(__('admin.fields.description'))
                    ->wrap()
                    ->limit(60)
                    ->description(fn (BankStatementLine $record) => $record->reference),
                TextColumn::make('amount')
                    ->label(__('admin.fields.amount'))
                    ->money('EGP')
                    ->alignRight()
                    // Money in and money out read differently at a glance, which is most of what an
                    // operator is doing on this screen.
                    ->color(fn (BankStatementLine $record) => (float) $record->amount >= 0 ? 'success' : 'danger'),
                TextColumn::make('age')
                    ->label(__('admin.bank.age'))
                    ->badge()
                    // Silent on a matched line: it is explained, and how long that took is not a
                    // question anyone is asking. Loud once an unexplained line passes a month.
                    ->getStateUsing(function (BankStatementLine $record) use ($service) {
                        if ($service->coverage($record)['fully']) {
                            return null;
                        }

                        return __('admin.bank.days_old', ['days' => $record->ageInDays()]);
                    })
                    ->color(fn (BankStatementLine $record) => $record->ageInDays() >= 30 ? 'danger' : 'gray')
                    ->placeholder('—'),
                TextColumn::make('match_state')
                    ->label(__('admin.fields.match_state'))
                    ->badge()
                    ->getStateUsing(function (BankStatementLine $record) use ($service) {
                        $coverage = $service->coverage($record);

                        if ($coverage['fully']) {
                            return __('admin.bank.matched');
                        }

                        // Partly explained is its own answer, not a rounding of "unmatched": a bank
                        // line covering two cheques is matched twice, and the operator needs to see
                        // what is still outstanding rather than start again.
                        return $coverage['matched'] != 0.0
                            ? __('admin.bank.partly_matched', ['amount' => number_format($coverage['outstanding'], 2)])
                            : __('admin.bank.unmatched');
                    })
                    ->color(function (BankStatementLine $record) use ($service) {
                        $coverage = $service->coverage($record);

                        return $coverage['fully'] ? 'success' : ($coverage['matched'] != 0.0 ? 'warning' : 'gray');
                    }),
            ])
            ->filters([
                Filter::make('unmatched')
                    ->label(__('admin.bank.only_unmatched'))
                    ->query(fn ($query) => $query->whereDoesntHave('matches'))
                    ->toggle(),
                Filter::make('aged')
                    ->label(__('admin.bank.only_aged'))
                    ->query(fn ($query) => $query->unmatchedOlderThan(30))
                    ->toggle(),
            ])
            ->headerActions([
                Action::make('import')
                    ->label(__('admin.actions.import_statement_lines'))
                    ->icon('heroicon-o-arrow-up-tray')
                    ->modalDescription(__('admin.helpers.import_statement_lines'))
                    // FR-USR-02: import is an ADMIN right, not a flavour of create. One wrong CSV
                    // rewrites hundreds of rows at once and the mistake is found later.
                    ->visible(fn (): bool => Imports::allowed())
                    ->authorize(fn (): bool => Imports::allowed())
                    ->schema([
                        FileUpload::make('file')
                            ->label(__('admin.fields.csv_file'))
                            ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel'])
                            // Kept in memory: the file is parsed and discarded, and a bank statement
                            // is not something to leave lying in storage.
                            ->storeFiles(false)
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        abort_unless(Imports::allowed(), 403);

                        /** @var BankStatement $statement */
                        $statement = $this->getOwnerRecord();
                        $upload = $data['file'] ?? null;

                        if (! $upload instanceof UploadedFile) {
                            Notification::make()->danger()->title(__('admin.errors.bank_statement_csv_empty'))->send();

                            return;
                        }

                        $importer = app(ImportBankStatementService::class);

                        try {
                            $rows = $importer->parseCsv((string) $upload->get());
                            $result = $importer->import($statement, $rows);
                        } catch (DomainException $e) {
                            // A refusal, not a fault — the operator is told which column is missing.
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        $statement->update(['source_filename' => $upload->getClientOriginalName()]);

                        Notification::make()
                            ->success()
                            ->title(__('admin.bank.import_done', [
                                'imported' => $result['imported'],
                                'skipped' => $result['skipped'],
                            ]))
                            ->body($statement->refresh()->isSelfConsistent()
                                ? null
                                : __('admin.bank.import_inconsistent'))
                            ->send();
                    }),
            ])
            ->recordActions([
                Action::make('match')
                    ->label(__('admin.actions.match_line'))
                    ->icon('heroicon-o-link')
                    ->visible(fn (BankStatementLine $record) => $this->canMatch()
                        && ! $service->coverage($record)['fully'])
                    ->authorize(fn () => $this->canMatch())
                    ->modalDescription(fn (BankStatementLine $record) => __('admin.helpers.match_line', [
                        'outstanding' => number_format($service->coverage($record)['outstanding'], 2),
                    ]))
                    ->schema(fn (BankStatementLine $record) => [
                        Select::make('journal_line_id')
                            ->label(__('admin.fields.book_posting'))
                            ->options(fn () => $service->candidatesFor($record)
                                ->mapWithKeys(function (JournalLine $l) {
                                    $entry = $l->getRelationValue('entry');

                                    return [$l->id => trim(sprintf(
                                        '%s · %s · %s',
                                        $entry instanceof JournalEntry ? $entry->entry_date->format('d/m/Y') : '',
                                        number_format((float) $l->debit > 0 ? (float) $l->debit : -(float) $l->credit, 2),
                                        $entry instanceof JournalEntry ? $entry->displayDescription() : ''
                                    ))];
                                })
                                ->all())
                            ->required()
                            ->searchable()
                            ->native(false)
                            ->helperText(__('admin.helpers.book_posting'))
                            ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.book_posting')),
                    ])
                    ->action(function (BankStatementLine $record, array $data) use ($service): void {
                        abort_unless($this->canMatch(), 403);

                        $journalLine = JournalLine::find($data['journal_line_id'] ?? null);

                        if (! $journalLine) {
                            Notification::make()->danger()->title(__('admin.errors.bank_match_missing_posting'))->send();

                            return;
                        }

                        try {
                            $service->match($record, $journalLine);
                        } catch (DomainException $e) {
                            // Every one of these refusals describes a mistake that would still
                            // BALANCE — so the message has to say what went wrong, not just "no".
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title(__('admin.bank.matched_done'))->send();
                    }),

                Action::make('unmatch')
                    ->label(__('admin.actions.unmatch_line'))
                    ->icon('heroicon-o-link-slash')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription(__('admin.helpers.unmatch_line'))
                    // `$record->matches`, not `matches()->exists()`: this table's own
                    // `->with('matches.journalLine.entry')` has already fetched them, so the
                    // relation query was a further statement per row on top of the four
                    // `coverage()` used to make. Falls back to a lazy load when nothing is loaded,
                    // which is the same single query it always was.
                    ->visible(fn (BankStatementLine $record) => $this->canMatch() && $record->matches->isNotEmpty())
                    ->authorize(fn () => $this->canMatch())
                    ->action(function (BankStatementLine $record) use ($service): void {
                        abort_unless($this->canMatch(), 403);

                        foreach ($record->matches()->get() as $match) {
                            if ($match instanceof BankMatch) {
                                $service->unmatch($match);
                            }
                        }

                        Notification::make()->success()->title(__('admin.bank.unmatched_done'))->send();
                    }),
            ])
            ->defaultSort('value_date')
            ->emptyStateIcon('heroicon-o-document-text')
            ->emptyStateHeading(__('admin.empty.bank_statement_lines.heading'))
            ->emptyStateDescription(__('admin.empty.bank_statement_lines.description'));
    }
}
