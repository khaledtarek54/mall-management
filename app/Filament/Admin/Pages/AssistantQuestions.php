<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Actions\GuideAction;
use App\Models\AssistantQuestion;
use App\Support\Modules;
use App\Support\TenantScope;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * What people asked "Ask Atriom", and what it could not answer.
 *
 * **This is the deliverable of the whole A phase.** The assistant itself is a search box over
 * material that already existed; what did not exist anywhere was a record of the questions an
 * operator asks that nothing in the system answers. That list decides two things nobody can
 * currently decide from evidence: which screen guides have a hole, and whether paying for a
 * language model would buy anything (docs/integrations/AI-ASSISTANT.md, phase B).
 *
 * **Grouped, because one person asking six times is not six problems.** The row is a QUESTION —
 * folded, so «فاتورة» and «فاتوره» are one row and "Credit Note" and "credit note" are too — and
 * the count is what makes it a priority list rather than a log.
 *
 * **Ranked by how often it was asked**, which is the only ordering that makes this screen useful:
 * a list of misses in date order is a feed, and a feed is something you read once.
 *
 * **Property-scoped like everything else.** A question is free text an operator typed and may name
 * a tenant, so it is `#[PropertyOwned]` and this screen shows the selected mall's. That is a
 * deliberate departure from the activity log, which spans the portfolio and pays for it with a
 * much narrower gate — here the aggregate is still useful per property, so the cheaper answer is
 * the right one.
 */
class AssistantQuestions extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQuestionMarkCircle;

    protected static ?string $slug = 'assistant-questions';

    protected string $view = 'filament.pages.assistant-questions';

    /**
     * Its OWN permission, unlike the assistant itself.
     *
     * The box needs none — every result it offers is already filtered through the target screen's
     * `canAccess()`, so a right there would grant what the reader already holds. This screen is
     * different in kind: it shows what OTHER PEOPLE typed, in their own words, and a question can
     * name a tenant. That is something to grant rather than something everyone has.
     */
    public static function canAccess(): bool
    {
        return Modules::enabled('assistant')
            && (Auth::user()?->can('assistant.review') ?? false);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->questions())
            ->defaultSort('asked', 'desc')
            ->columns([
                TextColumn::make('question')
                    ->label(__('admin.assistant.review.question'))
                    ->wrap()
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->having('question_folded', 'like', '%'.$search.'%')),

                TextColumn::make('asked')
                    ->label(__('admin.assistant.review.asked'))
                    ->badge()
                    ->sortable(),

                TextColumn::make('answered')
                    ->label(__('admin.assistant.review.answered'))
                    // The aggregate is what matters: a question answered SOMETIMES is a ranking
                    // problem, where one answered never is a missing guide. Two different fixes, so
                    // the column has to tell them apart rather than showing a yes/no.
                    ->badge()
                    ->color(fn ($state): string => $state > 0 ? 'success' : 'danger')
                    ->formatStateUsing(fn ($state, $record): string => $state > 0
                        ? __('admin.assistant.review.answered_n_of_m', ['n' => $state, 'm' => $record->asked])
                        : __('admin.assistant.review.never_answered')),

                TextColumn::make('top_key')
                    ->label(__('admin.assistant.review.led_to'))
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('locale')
                    ->label(__('admin.fields.locale'))
                    ->badge()
                    ->toggleable(),

                TextColumn::make('last_asked')
                    ->label(__('admin.assistant.review.last_asked'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('unanswered')
                    ->label(__('admin.assistant.review.unanswered_only'))
                    ->queries(
                        true: fn (Builder $query): Builder => $query->having('answered', '=', 0),
                        false: fn (Builder $query): Builder => $query->having('answered', '>', 0),
                        blank: fn (Builder $query): Builder => $query,
                    ),

                SelectFilter::make('locale')
                    ->label(__('admin.fields.locale'))
                    // Not an EntitySelect: this picks a VALUE from a fixed set, not a record.
                    ->options(['en' => 'English', 'ar' => 'العربية'])
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->where('locale', $data['value'])
                        : $query),
            ])
            ->emptyStateHeading(__('admin.assistant.review.empty_heading'))
            ->emptyStateDescription(__('admin.assistant.review.empty_body'));
    }

    /**
     * One row per distinct question.
     *
     * Every non-grouped column is an aggregate on purpose — MySQL runs `ONLY_FULL_GROUP_BY` by
     * default and would reject a bare column here, while SQLite would silently pick an arbitrary
     * row and the suite would stay green. That divergence is this codebase's most repeated trap.
     *
     * `MAX(id)` gives Filament a real record key, so the table paginates and sorts like any other
     * rather than needing a hand-rolled key resolver.
     *
     * @return Builder<AssistantQuestion>
     */
    protected function questions(): Builder
    {
        $assetId = TenantScope::currentAssetId();

        return AssistantQuestion::query()
            ->selectRaw('MAX(id) as id')
            ->selectRaw('question_folded')
            ->selectRaw('MAX(question) as question')
            ->selectRaw('COUNT(*) as asked')
            ->selectRaw('SUM(CASE WHEN matched = 1 THEN 1 ELSE 0 END) as answered')
            ->selectRaw('MAX(top_key) as top_key')
            ->selectRaw('MAX(locale) as locale')
            ->selectRaw('MAX(created_at) as last_asked')
            // A page is not a resource, so nothing scopes this for us. Written as a hard filter
            // rather than an `->when()`: a null property here would show every mall's questions,
            // and there is no state of this panel in which that is the intended read.
            ->where('asset_id', $assetId)
            ->groupBy('question_folded');
    }

    protected function getHeaderActions(): array
    {
        return [GuideAction::for(static::class)];
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.assistant.review.nav_label');
    }

    public function getTitle(): string
    {
        return __('admin.assistant.review.page_title');
    }

    public function getSubheading(): ?string
    {
        return __('admin.assistant.review.subheading');
    }
}
