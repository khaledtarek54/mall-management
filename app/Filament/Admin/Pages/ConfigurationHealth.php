<?php

namespace App\Filament\Admin\Pages;

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

/**
 * What is not configured yet, and what each gap breaks.
 *
 * `atriom:health` answers "is this deployment alive". Nothing answered **"is it set up"** — and the
 * two fail differently. A perfectly healthy installation bills every tenant through a floor rate
 * because nobody classified the charge codes, and issues tax invoices with no registration number
 * on them; neither shows up as an outage, and neither is visible until a tenant asks why they
 * cannot reclaim their VAT.
 *
 * `docs/GO-LIVE.md` is that list, verified by hand against the code — accurate on the day it was
 * written, and able to fall out of date silently every day after. This reads the live database.
 *
 * **The impact line is the feature.** A checklist of red dots is a nag; what makes one worth
 * opening is each row saying what happens if you leave it. "Tenants cannot reclaim the VAT you
 * charged them" is a sentence somebody acts on.
 */
class ConfigurationHealth extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?int $navigationSort = 96;

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

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.settings');
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
                    'impact' => $check['ok']
                        ? __("admin.config_health.checks.{$check['key']}.ok", ['detail' => $check['detail'], 'count' => $check['count']])
                        : __("admin.config_health.checks.{$check['key']}.impact", ['detail' => $check['detail'], 'count' => $check['count']]),
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
}
