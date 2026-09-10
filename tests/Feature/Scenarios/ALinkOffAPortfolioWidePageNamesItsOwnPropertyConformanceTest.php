<?php

use App\Support\PropertyIsolation;
use Tests\Support\PropertyLinks;

/**
 * **A SCREEN THAT CAN BE LOOKING AT ANOTHER PROPERTY MUST NAME THAT PROPERTY IN ITS LINKS.**
 *
 * Every admin route carries a `{tenant}` segment and `Resource::getUrl()` fills it from the
 * SWITCHER. On sixty-odd screens that is correct by construction — the record you are looking at
 * belongs to the selected mall or you could not have opened it. `AssetResource` is the exception it
 * is built to be: it lists the operator's WHOLE portfolio (`$isScopedToTenant = false`), because
 * managing the malls themselves sits above the per-property context and a newly created mall is
 * never the active one. So its tabs routinely show mall B's rows while the switcher says mall A.
 *
 * Reported from the panel as `/admin/VP/units/13/edit` → **404**: the link named the selected mall
 * and pointed at the looked-at mall's unit, and `UnitResource` is `ScopesToProperty`, so Filament
 * resolved the route-bound record through a query that could not contain it. Not a refusal anyone
 * can act on — a dead end from a row on screen.
 *
 * **THIS IS A GATE BECAUSE THE RULE WAS ALREADY WRITTEN DOWN AND STILL MISSED.**
 * `AssetStaffRelationManager` states it in full — *"The TENANT is passed explicitly … a relation
 * manager already knows the property it belongs to"* — and the two managers registered beside it in
 * the same `getRelations()` array did not follow it. A sentence is not a gate.
 *
 * Both halves are DERIVED from `App\Support\PropertyIsolation`, the register that already answers
 * "is this model scoped to one property", so screen sixty-seven is covered by being what it is
 * rather than by anyone remembering to add it here.
 */
it('names the property on every link out of a page that is not itself property-scoped', function () {
    $offenders = [];
    $owners = 0;
    $callSites = 0;

    foreach (PropertyLinks::filesByPortfolioWideOwner() as $owner => $files) {
        $owners++;

        foreach ($files as $file) {

            foreach (PropertyLinks::resourceLinksIn($file) as $link) {
                $callSites++;

                // Only a PROPERTY-SCOPED target 404s under the wrong mall. A link at another
                // portfolio-wide resource (the staff tab pointing at a User) resolves under any
                // tenant the operator may enter, so it is not a defect — that call site passes the
                // tenant anyway, which is better, but requiring it here would fire on correct code.
                if (! PropertyIsolation::isOwned($link['target']::getModel())) {
                    continue;
                }

                if (! $link['names_tenant']) {
                    $offenders[] = sprintf(
                        '%s:%d links at %s without naming a tenant — it will name whichever mall is in the switcher.',
                        str_replace(base_path().'/', '', $file),
                        $link['line'],
                        class_basename($link['target']),
                    );
                }
            }
        }
    }

    // THE SWEEP MUST HAVE FOUND SOMETHING TO SWEEP — and the premise has to be one that can
    // actually FAIL. An earlier version asserted that files were examined, which is implied by any
    // resource existing at all: a tautology dressed as a guard, the shape that let three gates in
    // this repo run green over a set they had stopped collecting.
    //
    // `$callSites` counts EVERY `SomeResource::getUrl(` the scanner resolved, not just the ones at
    // a property-scoped target. That distinction is deliberate: driving the property-scoped count
    // to zero is what SUCCESS looks like here — every call site moving to `PropertyLink::to()`
    // would be a good change, and a gate that fails on a good change is a gate people delete —
    // while the total going to zero means the tokeniser has stopped resolving call sites in the
    // real tree, which is the failure worth catching. (Seven today, across twenty-four owners.)
    expect($owners)->toBeGreaterThan(0, 'no portfolio-wide resource was discovered — the derivation has stopped matching');
    expect($callSites)->toBeGreaterThan(0, 'the scanner resolved no getUrl() call site at all — it has stopped matching the real tree');

    expect($offenders)->toBe([]);
});

it('reads a call site and not a sentence about one', function () {
    // The fix for the reported defect carries a docblock naming both `getUrl()` and the tenant,
    // because explaining the rule where it is applied is how this repo works. A grep-based gate
    // reads that explanation as a call site — the prose false-positive that has weakened three
    // gates here already — so the scanner drops comments before it tokenises, and this pins it.
    $file = tempnam(sys_get_temp_dir(), 'links').'.php';

    file_put_contents($file, <<<'PHP'
    <?php
    use App\Filament\Admin\Resources\Units\UnitResource;
    class Probe {
        public function table() {
            // UnitResource::getUrl('edit', ['record' => $r]) would name the switcher's mall.
            /** Always pass tenant: $this->getOwnerRecord() here. */
            return UnitResource::getUrl('edit', ['record' => $r]);
        }
    }
    PHP);

    $links = PropertyLinks::resourceLinksIn($file);
    unlink($file);

    // One call site, not three; and the `tenant:` in the prose above it did not vouch for it.
    expect($links)->toHaveCount(1)
        ->and($links[0]['names_tenant'])->toBeFalse();
});
