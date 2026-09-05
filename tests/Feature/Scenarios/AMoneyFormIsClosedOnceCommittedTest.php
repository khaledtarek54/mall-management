<?php

use App\Services\Accounting\LedgerPoster;
use App\Support\ChangeImpact;
use App\Support\Filament\AnnouncesLedgerRestatement;
use App\Support\MoneyFormPolicy;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\Field;
use Livewire\Livewire;
use Tests\Support\CommittedMoneyFixtures;

/**
 * Conformance gate — a committed money document's form is CLOSED, and stays closed
 * (CHANGE-IMPACT-PLAN §17, SW-240).
 *
 * WHY THIS EXISTS. §16 and §17 closed the open fields one report and one audit at a time — the paid
 * invoice's status control, the deposit form disagreeing with its own model, the payment status
 * dropdown that posted cash. Every one of those was a form shipping open with nothing to notice;
 * this is the notice. Three clauses, judged on the REAL mounted Edit page of every GL source that
 * has one, on a committed fixture:
 *
 *   1. A REFUSED field renders disabled — the model would throw on submit, and a form inviting a
 *      refusal toast is the deposit divergence (§16.3) again.
 *   2. `status` is never an enabled control on a committed record. No exemptions: every state past
 *      the first is derived or the outcome of a named act.
 *   3. A DERIVED field is disabled unless registered in {@see MoneyFormPolicy::OPEN_WHILE_DERIVED}
 *      with a reason — and then only on a page that announces the restatement, because DERIVED's
 *      own definition ends "the operator must be told".
 *
 * **What it deliberately does not judge.** NEUTRAL fields — the gate answers the LEDGER question,
 * and the money-without-ledger locks (the invoice's service period, the payment's gateway identity)
 * carry their own regression tests, because "evidence" cannot be derived structurally. And the
 * MarketingSpend relation-manager modal — no resource, so no Edit page to derive; its
 * deliberately-open DERIVED fields and its announcement are proved by
 * `AnActOnAPostedDocumentIsWhereItCanBeSeenTest` driving the real modal.
 *
 * **Why `readOnly` counts as closed.** `->readOnly()` fields answer `isDisabled(): false`, and the
 * first draft of the §17 audit reported Payroll's `net_paid` as a hole on exactly that misreading —
 * a readOnly field is displayed and not typeable, which is what closed means here.
 */
beforeEach(function () {
    ensureAllPropertiesAsset();
    $this->seed(RolesPermissionsSeeder::class);
    $this->asset = makeAsset(['code' => 'MF']);
    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/**
 * Every GL source with an admin Edit page, derived from the panel — never a hand list, so a source
 * that GROWS an Edit page joins the sweep by existing.
 *
 * @return array<class-string, class-string> model => edit page livewire class
 */
function editPagedMoneySources(): array
{
    $pages = [];

    foreach (Filament::getPanel('admin')->getResources() as $resource) {
        $model = $resource::getModel();

        if (! in_array($model, LedgerPoster::sources(), true)) {
            continue;
        }

        $edit = $resource::getPages()['edit'] ?? null;

        if ($edit !== null) {
            $pages[$model] = $edit->getPage();
        }
    }

    return $pages;
}

it('has a mountable committed fixture for every money source with an Edit page, and only those', function () {
    // Both directions, the DocumentSeriesOrdering lesson: a source that gains an Edit page must
    // gain a fixture or its form ships unswept, and a fixture for a source that lost its page is
    // a stale claim of coverage.
    expect(array_keys(CommittedMoneyFixtures::uiFixtures($this->asset)))
        ->toEqualCanonicalizing(array_keys(editPagedMoneySources()));
});

it('renders every refused or derived field closed on a committed record — or registered, with an announcing page', function () {
    $problems = [];
    $fieldsPerModel = [];

    foreach (editPagedMoneySources() as $model => $page) {
        $record = CommittedMoneyFixtures::uiFixtures($this->asset)[$model]();
        $form = Livewire::test($page, ['record' => $record->getKey()])->instance()->form;
        $announces = in_array(AnnouncesLedgerRestatement::class, class_uses_recursive($page), true);
        $short = class_basename($model);
        $fieldsPerModel[$short] = 0;

        foreach ($form->getFlatComponents() as $component) {
            if (! $component instanceof Field) {
                continue;
            }

            $fieldsPerModel[$short]++;
            $name = $component->getName();
            $closed = $component->isDisabled()
                || (method_exists($component, 'isReadOnly') && $component->isReadOnly());

            if ($name === 'status' && ! $closed) {
                $problems[] = "{$short}.status is an enabled control on a committed record — a status past the first one is the outcome of an act";

                continue;
            }

            $verdict = ChangeImpact::verdictFor($model, $name);

            if ($verdict === ChangeImpact::REFUSED && ! $closed) {
                $problems[] = "{$short}.{$name} is REFUSED at the model and enabled on the form — the operator types, submits, and reads a refusal toast";
            }

            if ($verdict === ChangeImpact::DERIVED && ! $closed) {
                if (! isset(MoneyFormPolicy::OPEN_WHILE_DERIVED[$model][$name])) {
                    $problems[] = "{$short}.{$name} is DERIVED and enabled with no MoneyFormPolicy entry — lock it, or register the decision with its reason";
                } elseif (! $announces) {
                    $problems[] = "{$short}.{$name} is registered open but ".class_basename($page).' does not announce restatements — DERIVED means the operator is told';
                }
            }
        }
    }

    // The gate must have measured something PER FORM, or one form's fields silently vanishing
    // keeps a global total green (the review measured 109 across nine forms — one form gone still
    // clears any whole-sweep floor). An unmountable fixture throws loudly at resolveRecord; this
    // floor catches the quieter failure, a form whose fields all stop rendering. Five is below
    // the sparsest real form (Custody renders six).
    foreach ($fieldsPerModel as $short => $count) {
        expect($count)->toBeGreaterThanOrEqual(5, "{$short}'s form rendered only {$count} fields — the sweep is no longer seeing it");
    }
    expect($problems)->toBe([], "\n".implode("\n", $problems));
});

it('keeps no stale exemption', function () {
    $stale = [];
    $pages = editPagedMoneySources();
    $fixtures = CommittedMoneyFixtures::uiFixtures($this->asset);

    foreach (MoneyFormPolicy::OPEN_WHILE_DERIVED as $model => $fields) {
        foreach ($fields as $name => $reason) {
            if (blank($reason)) {
                $stale[] = class_basename($model).".{$name} has no reason";
            }

            // Registered fields must really be DERIVED — an entry for a REFUSED or NEUTRAL field
            // is a category error that would silently widen the exemption's meaning.
            if (ChangeImpact::verdictFor($model, $name) !== ChangeImpact::DERIVED) {
                $stale[] = class_basename($model).".{$name} is registered open-while-DERIVED but ChangeImpact says otherwise";
            }
        }

        // …and must correspond to a form that still leaves them open. A registry describing locks
        // that have since landed reads as a list of holes that no longer exist. (MarketingSpend has
        // no Edit page, so its openness is asserted by the modal-driving regression test instead —
        // which is why it is deliberately NOT in the registry.)
        if (! isset($pages[$model])) {
            $stale[] = class_basename($model).' is registered but has no Edit page for the exemption to apply to';

            continue;
        }

        $form = Livewire::test($pages[$model], ['record' => $fixtures[$model]()->getKey()])->instance()->form;

        foreach (array_keys($fields) as $name) {
            $component = $form->getComponent($name);

            if ($component === null) {
                $stale[] = class_basename($model).".{$name} is registered but the form renders no such field";

                continue;
            }

            if ($component->isDisabled()) {
                $stale[] = class_basename($model).".{$name} is registered open but the form now locks it — delete the stale entry";
            }
        }
    }

    expect($stale)->toBe([], "\n".implode("\n", $stale));
});
