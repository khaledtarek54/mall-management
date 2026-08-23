<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Actions\GuideAction;
use App\Support\ConfigurationHealth as Checks;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Lang;

/**
 * What is not configured yet, and what each gap breaks.
 *
 * `atriom:health` answers "is this deployment alive". Nothing answered **"is it set up"** — and the
 * two fail differently. A perfectly healthy installation bills every tenant through a floor rate
 * because nobody classified the charge codes, and issues tax invoices with no registration number
 * on them; neither shows up as an outage, and neither is visible until a tenant asks why they
 * cannot reclaim their VAT.
 *
 * `docs/operations/GO-LIVE.md` is that list, verified by hand against the code — accurate on the day it was
 * written, and able to fall out of date silently every day after. This reads the live database.
 *
 * **The impact line is the feature.** A checklist of red dots is a nag; what makes one worth
 * opening is each row saying what happens if you leave it. "Tenants cannot reclaim the VAT you
 * charged them" is a sentence somebody acts on.
 */
class ConfigurationHealth extends Page implements HasTable
{
    use InteractsWithTable;

    protected function getHeaderActions(): array
    {
        return [
            GuideAction::for(static::class),
        ];
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string $routePath = 'configuration-health';

    protected string $view = 'filament.pages.report-hub';

    public static function getNavigationLabel(): string
    {
        return __('admin.config_health.nav_label');
    }

    public function getTitle(): string
    {
        return __('admin.config_health.page_title');
    }

    public function getSubheading(): ?string
    {
        $open = collect(Checks::run())->reject(fn (array $c) => $c['ok']);

        if ($open->isEmpty()) {
            return __('admin.config_health.all_clear');
        }

        return __('admin.config_health.summary', [
            'blocking' => $open->where('severity', Checks::BLOCKING)->count(),
            'advisory' => $open->where('severity', Checks::ADVISORY)->count(),
        ]);
    }

    /**
     * Whoever may change the configuration may see what is wrong with it.
     *
     * Deliberately not its own permission: an operator allowed to fix these settings and not
     * allowed to see which need fixing is a combination nobody wants.
     */
    public static function canAccess(): bool
    {
        return Auth::user()?->can('settings.view') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => collect(Checks::run())
                ->map(fn (array $check) => [
                    'id' => $check['key'],
                    'category' => __("admin.config_health.categories.{$check['category']}"),
                    'name' => __("admin.config_health.checks.{$check['key']}.name"),
                    // What happens if you leave it — the field that earns the page. A passing check
                    // says what it found instead, so "green" is evidence rather than an assertion.
                    //
                    // A failing check may state its case TWICE: `impact` for the blocking reading and
                    // an optional `advisory` for the softer one. Without that split, a check whose
                    // advisory state is ordinary borrows the blocking sentence and tells the operator
                    // something untrue about their books — which is exactly what the payroll row did
                    // in its first cut, reporting ":count runs withheld nothing" with a count of 0.
                    // Checks with no `advisory` key are unaffected.
                    'impact' => self::sentenceFor($check),
                    'ok' => $check['ok'],
                    'severity' => $check['severity'],
                ])
                ->all())
            ->columns([
                TextColumn::make('name')
                    ->label(__('admin.config_health.check'))
                    ->weight('medium')
                    ->description(fn (array $record): string => $record['impact'])
                    ->wrap(),
                TextColumn::make('ok')
                    ->label(__('admin.config_health.status'))
                    ->badge()
                    ->alignEnd()
                    ->formatStateUsing(fn (array $record): string => $record['ok']
                        ? __('admin.config_health.set')
                        : __("admin.config_health.severities.{$record['severity']}"))
                    ->color(fn (array $record): string => match (true) {
                        $record['ok'] => 'success',
                        $record['severity'] === Checks::BLOCKING => 'danger',
                        default => 'warning',
                    }),
            ])
            ->groups([
                Group::make('category')
                    ->label(__('admin.fields.category'))
                    ->getKeyFromRecordUsing(fn (array $record): string => $record['category'])
                    ->getTitleFromRecordUsing(fn (array $record): string => $record['category']),
            ])
            ->defaultGroup('category')
            ->paginated(false)
            ->emptyStateIcon('heroicon-o-clipboard-document-check');
    }

    /**
     * The sentence a check renders, given its state.
     *
     * Three keys, not two: `ok` for a passing check, `impact` for a failing BLOCKING one, and an
     * optional `advisory` for a failing advisory one. The optional third exists because the two
     * readings can be genuinely different claims — "your books are missing a liability" versus
     * "nothing is wrong yet, but the first run will withhold nothing" — and a check forced to
     * borrow the blocking wording states a falsehood in its most ordinary state.
     *
     * @param  array{key: string, severity: string, ok: bool, detail: string, count: int}  $check
     */
    private static function sentenceFor(array $check): string
    {
        $base = "admin.config_health.checks.{$check['key']}";
        $replace = ['detail' => $check['detail'], 'count' => $check['count']];

        if ($check['ok']) {
            return __("{$base}.ok", $replace);
        }

        $advisory = "{$base}.advisory";

        // `fallback: false`. `Lang::has()` falls back to English by default, so the obvious
        // spelling would render an English sentence inside an Arabic panel — which this project
        // treats as a defect everywhere else. A locale gap should drop to `impact`, which is
        // translated, and `TranslationKeyConformanceTest` fails the build on the gap itself.
        return $check['severity'] === Checks::ADVISORY && Lang::has($advisory, null, false)
            ? __($advisory, $replace)
            : __("{$base}.impact", $replace);
    }
}
