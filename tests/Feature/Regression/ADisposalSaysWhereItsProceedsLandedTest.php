<?php

use App\Filament\Admin\Resources\FixedAssets\Pages\EditFixedAsset;
use App\Models\FixedAsset;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;

/**
 * SW-190 — the dispose modal's "Proceeds to" picker could never appear, so every disposal posted
 * its sale proceeds to CASH.
 *
 * Measured at HEAD 2026-09-04: `FixedAssetActions:66` conditioned the picker on
 * `(float) $get('proceeds') > 0` while the `proceeds` field beside it carried no `->live()`, so
 * nothing re-rendered the modal after the amount was typed. And a hidden Filament field is not
 * dehydrated — `Filament\Schemas\Components\Concerns\HasState::isHiddenAndNotDehydratedWhenHidden()`
 * FORGETS the state path — so `proceeds_account` never reached `$data`,
 * `DisposeFixedAssetService:61` fell to its `?? 'cash'`, and `FixedAssetDisposalJournalizer:66`
 * resolved the proceeds line through `MoneyAccount::for(null, 'cash', …)`. An asset sold and
 * BANKED debited cash on hand.
 *
 * The repair is to stop conditioning it at all rather than to make the amount live: a money RAIL
 * whose answer rides on a blur that races the submit is a rail that is sometimes not asked, and
 * `BankAccountField` is deliberately not hidden on a cash rail for exactly this reason.
 *
 * The last case here is the CLASS, swept from disk. Its sibling offender — the charge-schedule
 * "does not prorate" toggle, conditioned on a non-live `frequency` — cannot be proved
 * behaviourally at all: the Livewire harness always evaluates `visible()` against the state the
 * test just set, so a `->live()` fix and its absence are indistinguishable from a test. The static
 * sweep is the only thing that can see it.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();

    $this->asset = makeAsset();
    $this->fixedAsset = FixedAsset::create([
        'asset_id' => $this->asset->id,
        'name' => 'Food-court chiller',
        'tag' => 'FA-'.uniqid(),
        'acquisition_date' => '2026-01-01',
        'acquisition_cost' => 12000,
        'salvage_value' => 0,
        'useful_life_months' => 12,
        'method' => 'straight_line',
        'funded_from' => 'cash',
    ]);

    $this->actingAs(makeUser('accounting', [$this->asset->id]));
});

it('keeps the proceeds account the operator picked, whatever the amount field says', function () {
    // Zero is the state the modal OPENS in — `proceeds` defaults to 0 — and it is the only state
    // the old conditional ever saw, because nothing synced the amount back to the server. With the
    // picker hidden here, the answer below was dropped before the service was ever called.
    asTenant($this->asset, function () {
        Livewire::test(EditFixedAsset::class, ['record' => $this->fixedAsset->getRouteKey()])
            ->callAction('dispose', data: [
                'disposed_on' => now()->toDateString(),
                'proceeds' => 0,
                'proceeds_account' => 'bank',
            ])
            ->assertHasNoActionErrors();
    });

    expect($this->fixedAsset->fresh()->disposal->proceeds_account)->toBe('bank');
});

it('records a banked sale against the bank', function () {
    // Control — the ordinary sale still goes through the modal end to end. This one passed before
    // the fix too (with proceeds already set, the picker was visible and therefore dehydrated),
    // which is exactly why it is the control and not the proof.
    asTenant($this->asset, function () {
        Livewire::test(EditFixedAsset::class, ['record' => $this->fixedAsset->getRouteKey()])
            ->callAction('dispose', data: [
                'disposed_on' => now()->toDateString(),
                'proceeds' => 5000,
                'proceeds_account' => 'bank',
            ])
            ->assertHasNoActionErrors();
    });

    $disposal = $this->fixedAsset->fresh()->disposal;

    expect($disposal)->not->toBeNull()
        ->and((float) $disposal->proceeds)->toBe(5000.0)
        ->and($disposal->proceeds_account)->toBe('bank');
});

it('leaves a scrapped asset on cash when nobody states a rail', function () {
    // Control — the default still lands, so making the picker unconditional and required did not
    // turn every scrapping into a decision the operator has to invent.
    asTenant($this->asset, function () {
        Livewire::test(EditFixedAsset::class, ['record' => $this->fixedAsset->getRouteKey()])
            ->callAction('dispose', data: [
                'disposed_on' => now()->toDateString(),
                'proceeds' => 0,
            ])
            ->assertHasNoActionErrors();
    });

    expect($this->fixedAsset->fresh()->disposal->proceeds_account)->toBe('cash');
});

it('never conditions a field on a sibling the operator cannot reach', function () {
    // A `visible()`/`hidden()` closure reading `$get('x')` is a promise that the schema re-renders
    // when `x` moves — true only if `x` is `->live()`. Without it the condition is frozen at
    // whatever `x` held when the modal opened, and the field either never appears (this row) or
    // never disappears (the charge-schedule toggle, which then writes a decision onto a row the
    // rule was never meant to reach).
    $stripComments = function (string $source): string {
        $out = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $out .= $token[1];

                continue;
            }

            $out .= $token;
        }

        return $out;
    };

    // The text of the first argument to each `->visible(` / `->hidden(`, matched by PAREN DEPTH and
    // not by a regex: a condition closure routinely contains calls, arrays and nested parens, and a
    // line-based read stops at the first one.
    $conditions = function (string $source): array {
        $found = [];

        foreach (['->visible(', '->hidden('] as $needle) {
            $offset = 0;

            while (($at = strpos($source, $needle, $offset)) !== false) {
                $i = $at + strlen($needle);
                $start = $i;
                $depth = 1;
                $quote = null;
                $length = strlen($source);

                while ($i < $length && $depth > 0) {
                    $char = $source[$i];

                    if ($quote !== null) {
                        if ($char === '\\') {
                            $i += 2;

                            continue;
                        }

                        if ($char === $quote) {
                            $quote = null;
                        }
                    } elseif ($char === "'" || $char === '"') {
                        $quote = $char;
                    } elseif ($char === '(') {
                        $depth++;
                    } elseif ($char === ')') {
                        $depth--;
                    }

                    $i++;
                }

                $found[] = substr($source, $start, $i - 1 - $start);
                $offset = $at + strlen($needle);
            }
        }

        return $found;
    };

    // name => the chain that follows each `X::make('name')`, so "is this field live" is answered
    // from the field's OWN chain rather than from anywhere in the file.
    $chains = function (string $source): array {
        preg_match_all(
            "/\b[A-Za-z_][A-Za-z0-9_]*::make\(\s*'([A-Za-z0-9_.]+)'\s*\)/",
            $source,
            $matches,
            PREG_OFFSET_CAPTURE,
        );

        $chains = [];
        $count = count($matches[0]);

        for ($i = 0; $i < $count; $i++) {
            $from = $matches[0][$i][1];
            $to = ($i + 1 < $count) ? $matches[0][$i + 1][1] : strlen($source);
            $chains[$matches[1][$i][0]][] = substr($source, $from, $to - $from);
        }

        return $chains;
    };

    $files = 0;
    $conditionCount = 0;
    $conditionsReadingAField = 0;
    $offenders = [];

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Filament')));

    foreach ($iterator as $file) {
        if ($file->isDir() || $file->getExtension() !== 'php') {
            continue;
        }

        $files++;
        $source = $stripComments((string) file_get_contents($file->getPathname()));
        $fieldChains = $chains($source);

        $live = [];

        foreach ($fieldChains as $name => $declarations) {
            foreach ($declarations as $declaration) {
                if (str_contains($declaration, '->live(')) {
                    $live[$name] = true;
                }
            }
        }

        foreach ($conditions($source) as $condition) {
            $conditionCount++;

            if (! preg_match_all("/\\\$get\(\s*'([A-Za-z0-9_.]+)'\s*\)/", $condition, $reads)) {
                continue;
            }

            $conditionsReadingAField++;

            foreach (array_unique($reads[1]) as $dependency) {
                // A path this file does not declare belongs to a parent schema or a repeater's
                // owner, and this sweep cannot see whether that one is live.
                if (! isset($fieldChains[$dependency]) || isset($live[$dependency])) {
                    continue;
                }

                $offenders[] = str_replace(base_path().'/', '', $file->getPathname())." → \$get('{$dependency}')";
            }
        }
    }

    // The premise, three ways: measured at HEAD 2026-09-04 as 629 files, 614 `visible()`/`hidden()`
    // conditions, and 93 of those reading `$get`. A sweep that finds no conditions — or finds them
    // and never resolves one to a field — is reporting on a panel it never read.
    expect($files)->toBeGreaterThan(400)
        ->and($conditionCount)->toBeGreaterThan(400)
        ->and($conditionsReadingAField)->toBeGreaterThan(40)
        ->and(array_values(array_unique($offenders)))->toBe([]);
});
