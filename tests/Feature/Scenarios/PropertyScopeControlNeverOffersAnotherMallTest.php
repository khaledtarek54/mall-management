<?php

use App\Filament\Admin\Resources\AccountMappings\Pages\EditAccountMapping;
use App\Filament\Admin\Resources\DocumentTemplates\Pages\CreateDocumentTemplate;
use App\Models\AccountMapping;
use App\Models\DocumentTemplate;
use App\Models\LedgerAccount;
use App\Support\Filament\PropertyField;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Illuminate\Validation\Rules\In;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The five portfolio-config screens state a SCOPE; they never list malls.
 *
 * ## What this is for
 *
 * Every other property field in the panel means *"which mall does this record belong to?"* — one
 * right answer, so `PropertyField::make()` shows it and locks it. These five ask a different
 * question: *"does this row apply to the whole portfolio, or only to this mall?"* A null `asset_id`
 * is one of the two valid answers — the house wording, the national holiday, the operator-wide
 * department, the global posting map — and all four resolvers query `whereNull('asset_id')` as
 * their fallback tier, so it cannot be pinned away without making the portfolio row unwritable
 * through its own form.
 *
 * They used to render a free `EntitySelect`. That was never an isolation leak: the picker resolves
 * a submitted value's label through the property-scoped `pickable()` query, so it could neither
 * offer nor accept a mall outside the operator's visible set — measured on a two-mall install, it
 * offered exactly the mall in the switcher. The defect was that a SCOPE question wore a PROPERTY
 * PICKER, so an enabled dropdown read as "choose a mall" and looked like a leak on the one screen
 * family where it was not one.
 *
 * So this gate asserts the property the control now makes true, rather than the absence of a bug:
 * **no screen in the panel offers a property other than the one in the switcher.** Test A derives
 * the screens from {@see PropertyField::PORTFOLIO_LEVEL} rather than naming them, so a sixth
 * portfolio-config screen is swept by being registered.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
});

/** The Resource class that owns each registered PORTFOLIO_LEVEL form, derived from its path. */
$registeredResources = function (): array {
    $base = app_path('Filament/Admin/Resources');

    return collect(array_keys(PropertyField::PORTFOLIO_LEVEL))
        ->map(fn (string $path) => basename(dirname(dirname($path))))
        ->map(fn (string $dir) => collect(glob($base.'/'.$dir.'/*Resource.php') ?: [])->first())
        ->filter()
        ->map(fn ($f) => 'App\\Filament\\Admin\\Resources\\'.str_replace('/', '\\', str_replace([$base.'/', '.php'], '', $f)))
        ->filter(fn ($c) => class_exists($c) && is_subclass_of($c, Resource::class))
        ->values()
        ->all();
};

it('A: offers only the house row and the mall in the switcher, on every portfolio-config screen', function () use ($registeredResources) {
    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $here = makeAsset(['code' => 'AW', 'name' => 'Atriom Walk']);
    $next_door = makeAsset(['code' => 'XX', 'name' => 'Other Mall']);
    Filament::setTenant($here);

    $resources = $registeredResources();
    $offenders = [];
    $checked = 0;

    try {
        foreach ($resources as $resource) {
            $create = $resource::getPages()['create'] ?? null;

            if ($create === null) {
                continue;
            }

            $field = Livewire::test($create->getPage())->instance()->form->getComponent('asset_id');

            if ($field === null) {
                $offenders[] = class_basename($resource).' has no asset_id control at all';

                continue;
            }

            $checked++;
            $keys = array_map('strval', array_keys($field->getOptions()));

            // The house row must stay reachable — that is the whole reason these five are not
            // pinned, and a control that dropped it would pass a "no foreign mall" check alone.
            if (! in_array('', $keys, true)) {
                $offenders[] = class_basename($resource).' no longer offers the portfolio row';
            }

            if (! in_array((string) $here->id, $keys, true)) {
                $offenders[] = class_basename($resource).' does not offer the selected mall';
            }

            $foreign = array_diff($keys, ['', (string) $here->id]);

            if ($foreign !== []) {
                $offenders[] = class_basename($resource).' offers '.implode('/', $foreign).' — a property other than the one in the switcher';
            }
        }
    } finally {
        Filament::setTenant(null, isQuiet: true);
    }

    // A sweep that matched nothing passes every assertion below it.
    expect($checked)->toBe(count($resources), 'The sweep skipped a registered screen — it is checking less than it reports.');
    expect($checked)->toBeGreaterThan(3);

    expect($offenders)->toBe([], implode('; ', $offenders));

    // And the control must be describing the mall we actually set, not a coincidence.
    expect($next_door->id)->not->toBe($here->id);
});

it('B: writes the house row, writes this mall, and refuses the mall next door', function () {
    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $here = makeAsset(['code' => 'AW', 'name' => 'Atriom Walk']);
    $next_door = makeAsset(['code' => 'XX', 'name' => 'Other Mall']);
    Filament::setTenant($here);

    try {
        // The house row — blank, which is what the portfolio tier is.
        Livewire::test(CreateDocumentTemplate::class)
            ->fillForm(['key' => 'invoice.footer', 'asset_id' => '', 'body_en' => 'house'])
            ->call('create');

        expect(DocumentTemplate::query()->where('body_en', 'house')->value('asset_id'))->toBeNull();

        // This mall's override.
        Livewire::test(CreateDocumentTemplate::class)
            ->fillForm(['key' => 'invoice.footer', 'asset_id' => (string) $here->id, 'body_en' => 'mine'])
            ->call('create');

        expect((int) DocumentTemplate::query()->where('body_en', 'mine')->value('asset_id'))->toBe($here->id);

        // The crafted payload the disabled control stands in for. A Livewire state value is
        // attacker-controlled, so the ->rules() guard is the actual gate — not the option list.
        Livewire::test(CreateDocumentTemplate::class)
            ->fillForm(['key' => 'invoice.terms', 'asset_id' => (string) $next_door->id, 'body_en' => 'crafted'])
            ->call('create');

        expect(DocumentTemplate::query()->where('body_en', 'crafted')->exists())
            ->toBeFalse('A crafted payload filed a portfolio-config row against the mall next door.');
    } finally {
        Filament::setTenant(null, isQuiet: true);
    }
});

it('B2: keeps BOTH refusal layers, because only one of them is ours', function () {
    // Test B proves the refusal; it does NOT prove which layer delivers it, and that distinction
    // cost a green mutation run. Deleting our ->rules() guard left B fully green, because Filament's
    // Radio auto-adds an `In` rule over its own option keys and that fired first. Both layers are
    // real and both are worth having — but an upstream rule is an implementation detail that can
    // change in a release and would silently remove a gate, so it is PINNED as a contract here
    // (same reasoning as FilamentActionDispatchContractTest) and our own guard is proved directly.
    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $here = makeAsset(['code' => 'AW', 'name' => 'Atriom Walk']);
    $next_door = makeAsset(['code' => 'XX', 'name' => 'Other Mall']);
    Filament::setTenant($here);

    try {
        $rules = Livewire::test(CreateDocumentTemplate::class)
            ->instance()->form->getComponent('asset_id')->getValidationRules();

        // Layer 1 — upstream. If a Filament release stops adding this, we want to know.
        expect(collect($rules)->contains(fn ($rule) => $rule instanceof In))
            ->toBeTrue('Filament no longer constrains a Radio to its own options — the upstream half of this gate is gone.');

        // Layer 2 — ours. Invoked directly, because in the full-form path upstream refuses first
        // and a test that only submits the form cannot tell the two apart.
        $ours = collect($rules)->filter(fn ($rule) => $rule instanceof Closure);
        expect($ours)->not->toBeEmpty('The scope control lost its own property guard.');

        $refused = false;
        foreach ($ours as $rule) {
            try {
                $rule('asset_id', (string) $next_door->id, fn () => null);
            } catch (HttpException) {
                $refused = true;
            }
        }
        expect($refused)->toBeTrue('Our own guard allowed a property other than the one in the switcher.');

        // The control: the same guard must ACCEPT the selected mall and the house row, or it would
        // pass the refusal above by refusing everything.
        foreach ($ours as $rule) {
            $rule('asset_id', (string) $here->id, fn () => null);
            $rule('asset_id', '', fn () => null);
        }
    } finally {
        Filament::setTenant(null, isQuiet: true);
    }
});

it('C: shows a row belonging to another mall, refuses to retarget it, and keeps it on save', function () {
    // Three of the five scope their list to `null ∪ visible`, so this cannot arise there — the
    // DocumentTemplate edit route genuinely cannot resolve a foreign row. TWO can: the posting map
    // has no getEloquentQuery() at all (it is #[PortfolioShared] accounting config) and owner
    // requests scope to the operator's ASSIGNED set rather than the selected mall. A two-option
    // toggle would render such a row as "All properties" and silently re-home it on save — a
    // data-loss bug introduced by a UI fix — so the row's own property is shown as a third option
    // and the control is disabled.
    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $here = makeAsset(['code' => 'AW', 'name' => 'Atriom Walk']);
    $next_door = makeAsset(['code' => 'XX', 'name' => 'Other Mall']);
    Filament::setTenant($here);

    $account = LedgerAccount::query()->first() ?? LedgerAccount::query()->create([
        'code' => '41101',
        'name_en' => 'Rent revenue',
        'name_ar' => 'إيراد الإيجار',
        'type' => 'revenue',
        'is_postable' => true,
    ]);

    $foreign = AccountMapping::query()->create([
        'key' => 'rent_revenue',
        'ledger_account_id' => $account->id,
        'asset_id' => $next_door->id,
    ]);

    try {
        $page = Livewire::test(EditAccountMapping::class, ['record' => $foreign->getRouteKey()]);
        $field = $page->instance()->form->getComponent('asset_id');

        expect($field->isDisabled())->toBeTrue('A row belonging to another mall must not be retargetable.');
        expect(array_map('strval', array_keys($field->getOptions())))->toContain((string) $next_door->id);

        // Saving must leave the property exactly where it was — the row is shown, not adopted.
        $page->call('save');

        expect((int) $foreign->fresh()->asset_id)->toBe($next_door->id);
    } finally {
        Filament::setTenant(null, isQuiet: true);
    }
});
