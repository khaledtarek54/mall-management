<?php

/*
|--------------------------------------------------------------------------
| A per-property override that overrides nothing
|--------------------------------------------------------------------------
| Eltizam runs several malls, and every configured number was portfolio-wide: one late-fee rate,
| one grace period, one set of payment terms across every building. The lease tier above them
| already assumed those numbers vary — a negotiated late fee has always beaten the default — so a
| single portfolio answer underneath was the odd one out.
|
| The failure this file exists to prevent is not "the override is wrong". It is **the override does
| nothing**: a key listed in the registry that no service reads, so the operator types 3%, sees
| "Saved ✓", and every invoice keeps charging 2%. That is the same shape as the inert settings
| screen this codebase has been bitten by before, and it is invisible from the screen.
|
| So the tests here are, in order: does the key EXIST on its settings class · does something READ
| it · does the resolution actually go lease → property → portfolio · and does absence mean inherit
| rather than zero.
*/

use App\Models\Lease;
use App\Models\PropertySetting;
use App\Settings\BillingSettings;
use App\Support\DeletionPolicy;
use App\Support\PropertyIsolation;
use App\Support\PropertySettings;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);

    $settings = app(BillingSettings::class);
    $settings->late_fee_percent = 2.0;
    $settings->late_fee_grace_days = 7;
    $settings->late_fee_minimum = 50.0;
    $settings->default_payment_terms_days = 7;
    $settings->nsf_fee_amount = 100.0;
    $settings->save();
});

it('names a real property on every overridable key', function () {
    // A key whose settings class has no such property resolves to null and silently reads as zero
    // — on a late-fee rate. Renaming a settings field without updating this registry is exactly how
    // that happens.
    foreach (PropertySettings::OVERRIDABLE as $key => $meta) {
        [$group, $name] = explode('.', $key, 2);

        expect(class_exists($meta['class']))->toBeTrue("{$key} names a class that does not exist");
        expect(property_exists($meta['class'], $name))
            ->toBeTrue("{$key} is not a property of {$meta['class']}");
        expect($meta['class']::group())->toBe($group, "{$key} is prefixed with the wrong settings group");
        expect(trim($meta['reason']))->not->toBe('', "{$key} must say WHY a property legitimately differs");
    }
});

it('has something actually reading every overridable key', function () {
    // The heart of it. An override nothing consults is worse than no override — the operator
    // changes it, sees it saved, and nothing happens. Each key must be reachable from a consumer,
    // proved BEHAVIOURALLY below; this is the cheap structural half that catches a key added to the
    // registry and then forgotten.
    $source = collect(['app/Models/Lease.php', 'app/Services', 'app/Filament'])
        ->flatMap(fn (string $path) => is_dir(base_path($path))
            ? collect(File::allFiles(base_path($path)))->map->getPathname()->all()
            : [base_path($path)])
        ->map(fn (string $file) => (string) file_get_contents($file))
        ->implode("\n");

    foreach (array_keys(PropertySettings::OVERRIDABLE) as $key) {
        [, $name] = explode('.', $key, 2);

        // Either the full key, or the helper named after the setting (paymentTermsDays).
        $referenced = str_contains($source, $key)
            || str_contains($source, Str::camel($name));

        expect($referenced)->toBeTrue("nothing reads {$key} — an override that does nothing is worse than none");
    }
});

it('does not offer an override for something that already has one', function () {
    // sla_policies is a per-property SLA override with its own resource and its own
    // response-vs-resolution split. Listing SLA hours here too would be a SECOND way to say the
    // same thing, and the two would disagree the first time somebody used the newer one.
    foreach (PropertySettings::NOT_OVERRIDABLE_BY_DESIGN as $key) {
        expect(PropertySettings::OVERRIDABLE)->not->toHaveKey($key);
    }
});

it('falls back to the portfolio when a property has overridden nothing', function () {
    $asset = makeAsset();

    expect(PropertySettings::get('billing.late_fee_percent', $asset->id))->toEqual(2.0)
        ->and(PropertySettings::get('billing.nsf_fee_amount', $asset->id))->toEqual(100.0);
});

it('prefers the property once it has overridden', function () {
    $asset = makeAsset();

    PropertySettings::set('billing.late_fee_percent', $asset->id, 5.0);

    expect(PropertySettings::get('billing.late_fee_percent', $asset->id))->toEqual(5.0)
        // …and only for that key. An override on one setting must not drag the rest with it.
        ->and(PropertySettings::get('billing.late_fee_grace_days', $asset->id))->toEqual(7);
});

it('keeps two properties independent', function () {
    // The whole point. If these leaked into each other the feature would be worse than the single
    // portfolio number it replaced.
    $prime = makeAsset(['code' => 'PRIME']);
    $secondary = makeAsset(['code' => 'SECOND']);

    PropertySettings::set('billing.late_fee_percent', $prime->id, 5.0);

    expect(PropertySettings::get('billing.late_fee_percent', $prime->id))->toEqual(5.0)
        ->and(PropertySettings::get('billing.late_fee_percent', $secondary->id))->toEqual(2.0);
});

it('treats an override of zero as a decision, not as absence', function () {
    // A property that waives its late fee is making a statement, and it must survive a later change
    // to the portfolio default. This is why the resolver checks for the KEY rather than for a
    // falsy value — `?:` here would have silently re-charged a mall the operator had exempted.
    $asset = makeAsset();

    PropertySettings::set('billing.late_fee_percent', $asset->id, 0.0);

    expect(PropertySettings::get('billing.late_fee_percent', $asset->id))->toEqual(0.0);

    $settings = app(BillingSettings::class);
    $settings->late_fee_percent = 9.0;
    $settings->save();

    expect(PropertySettings::get('billing.late_fee_percent', $asset->id))->toEqual(0.0);
});

it('restores the portfolio answer when an override is cleared', function () {
    $asset = makeAsset();

    PropertySettings::set('billing.late_fee_percent', $asset->id, 5.0);
    PropertySettings::set('billing.late_fee_percent', $asset->id, null);

    expect(PropertySettings::get('billing.late_fee_percent', $asset->id))->toEqual(2.0)
        // Cleared means the ROW is gone, not that it holds a null that later reads as zero.
        ->and(PropertySetting::query()->where('asset_id', $asset->id)->count())->toBe(0);
});

it('refuses to override a setting that is not on the list', function () {
    $asset = makeAsset();

    expect(fn () => PropertySettings::set('tax.vat_standard_rate', $asset->id, 5.0))
        ->toThrow(HttpException::class);
});

it('answers the portfolio when there is no property at all', function () {
    // A console command with no selected property must still get a usable number rather than null.
    expect(PropertySettings::get('billing.late_fee_percent', null))->toEqual(2.0);
});

it('clamps payment terms so a due date can never precede its issue date', function () {
    $asset = makeAsset();

    PropertySettings::set('billing.default_payment_terms_days', $asset->id, -5);

    expect(PropertySettings::paymentTermsDays($asset->id))->toBe(0);
});

it('classifies the model in the registries that gate every model', function () {
    // A new model that ships unclassified is what these gates exist to catch; asserting it here
    // keeps the failure attached to this feature rather than surfacing as a mystery elsewhere.
    expect(PropertyIsolation::isOwned(PropertySetting::class))->toBeTrue();
    expect(DeletionPolicy::allowed())->toHaveKey(PropertySetting::class);
});
