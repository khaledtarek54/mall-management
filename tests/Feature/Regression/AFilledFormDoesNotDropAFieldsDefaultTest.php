<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\Leases\Pages\EditLease;
use App\Models\Lease;
use App\Models\User;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Symfony\Component\Finder\Finder;

/**
 * `fillForm()` SETS THE WHOLE STATE — a field left out of it loses its own `->default()`.
 *
 * The Terminate modal named `termination_date` and `cancel_open_invoices` and stopped there, so
 * `credit_unearned` arrived null and a Toggle reads null as OFF. Its `->default(true)` was in the
 * source, correct, and inert. Reported from the panel as "the toggle is disabled by default".
 *
 * The cost is money the tenant does not owe: terminating with the credit switched off leaves every
 * invoice raised past the termination date standing in full. On the lease this was found on that
 * was three months of rent. And the operator sees an off toggle, not a bug, so nothing prompts them
 * to turn it on.
 *
 * Two tests, because they fail for different reasons. The first drives the real modal — the only
 * thing that proves what the operator actually sees. The second is the sweep, because this shape is
 * invisible in review (both halves look right on their own) and the next action to grow a default
 * would reintroduce it silently.
 */
beforeEach(function (): void {
    $this->seed(RolesPermissionsSeeder::class);
});

it('opens the terminate modal with the unearned-rent credit switched ON', function (): void {
    $lease = Lease::factory()->create([
        'status' => 'active',
        'commencement_date' => now()->subMonths(6)->startOfMonth(),
        'expiry_date' => now()->addMonths(6)->endOfMonth(),
    ]);
    $asset = $lease->unit->asset;

    $user = User::factory()->create();
    $user->assignRole('super_admin');
    $this->actingAs($user);
    Filament::setTenant($asset);

    $state = Livewire::test(EditLease::class, ['record' => $lease->id])
        ->mountAction('terminate')
        ->get('mountedActions')[0]['data'] ?? [];

    // The control: a default that WAS honoured, so a state read that returned nothing
    // cannot pass this test by accident.
    expect($state['cancel_open_invoices'] ?? null)->toBeTrue();
    expect($state['credit_unearned'] ?? null)->toBeTrue();
});

it('never lets a static fillForm array drop a field that declares a default', function (): void {
    $offenders = [];
    $swept = 0;

    foreach (Finder::create()->files()->in(app_path('Filament'))->name('*.php') as $file) {
        $source = $file->getContents();

        if (! preg_match_all('/->fillForm\(\[/', $source, $m, PREG_OFFSET_CAPTURE)) {
            continue;
        }

        foreach ($m[0] as [$match, $offset]) {
            $open = $offset + strlen($match) - 1;
            $depth = 0;
            $close = null;

            for ($i = $open; $i < strlen($source); $i++) {
                if ($source[$i] === '[') {
                    $depth++;
                } elseif ($source[$i] === ']') {
                    $depth--;
                    if ($depth === 0) {
                        $close = $i;
                        break;
                    }
                }
            }

            if ($close === null) {
                continue;
            }

            $swept++;
            preg_match_all("/'([a-z0-9_]+)'\s*=>/", substr($source, $open, $close - $open + 1), $named);
            $named = $named[1];

            // The schema belongs to this action only — stop at its ->action(), or the next
            // action's fields would be read as this one's.
            $tail = substr($source, $close, 9000);
            $stop = strpos($tail, '->action(');
            $schema = $stop === false ? $tail : substr($tail, 0, $stop);

            preg_match_all("/make\('([a-z0-9_]+)'\)((?:(?!make\(')[\s\S]){0,900}?)->default\(/", $schema, $fields);

            foreach ($fields[1] as $field) {
                if (! in_array($field, $named, true)) {
                    $line = substr_count(substr($source, 0, $offset), "\n") + 1;
                    $offenders[] = $file->getRelativePathname().':'.$line.' → '.$field;
                }
            }
        }
    }

    // A sweep that examined nothing passes for the wrong reason.
    expect($swept)->toBeGreaterThan(0, 'the sweep found no static fillForm arrays at all');

    expect($offenders)->toBe([], implode("\n", array_merge(
        ['fillForm() sets the whole state, so these fields silently lose their ->default():'],
        $offenders,
        ['Name them in the fillForm array, or drop the ->default() so the source stops claiming one.'],
    )));
});
