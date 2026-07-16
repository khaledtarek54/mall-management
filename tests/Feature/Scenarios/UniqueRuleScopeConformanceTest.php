<?php

use App\Filament\Admin\Resources\Employees\Pages\CreateEmployee;
use App\Models\Employee;
use App\Support\Portal;
use App\Support\TenantScope;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;

/**
 * Self-enforcing gate — a `unique` rule must never be keyed on a raw, client-supplied
 * scope (asset_id, lease_id, …).
 *
 * Found on the Equipment register, then confirmed across the Admin panel and — worst —
 * the tenant Portal. The mechanism:
 *
 *   - A Select's `->options()` scope the *rendering*, not the payload. Livewire state is
 *     attacker-controlled.
 *   - Laravel runs every field rule in ONE pass **before** any mutate hook, so
 *     `assertAssetInScope()` / `assertLeaseAssetInScope()` fire too late.
 *   - `Rule::unique` compiles to a raw query that no tenancy scope touches — and it still
 *     runs even when the sibling `in` rule is already refusing the value.
 *
 * So the rule answers "is this key taken under <scope>?" for a scope the user cannot see.
 * The write is refused either way — but the field erroring (or not) is a one-bit existence
 * oracle. In the Portal it is cross-TENANT: it leaks a competitor's leases and which
 * months they have declared sales for.
 *
 * The clamps (`TenantScope::clampAssetId` / `clampLeaseId`, `Portal::clampLeaseId`)
 * collapse it: out of scope → null → the rule matches nothing.
 *
 * This gate replaces a weaker first cut that only looked for `asset_id`, only globbed
 * `Resources/​*​/Schemas/*Form.php` (34 of 308 Filament files — missing every relation
 * manager), and whose positive half was satisfied by a *comment* mentioning the clamp.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
});

/**
 * Every PHP file under app/Filament — forms live in Schemas/, but also in relation
 * managers (app/Filament/Admin/RelationManagers/ sits outside Resources/ entirely) and
 * occasionally on pages.
 *
 * @return array<int,string>
 */
function filamentSources(): array
{
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Filament')));

    foreach ($it as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

/** Source with comments stripped — a comment naming the clamp must never satisfy the gate. */
function sourceWithoutComments(string $path): string
{
    $out = '';

    // token_get_all is exact where a regex would guess: it never mistakes a `//` inside a
    // string literal for a comment.
    foreach (token_get_all(file_get_contents($path)) as $token) {
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
}

/**
 * The argument text of every `->where(...)` call, extracted with a paren-balanced scan.
 *
 * A regex cannot do this: `->where('asset_id', (int) $get('asset_id'))` closes its first
 * paren after `(int`, so a non-greedy match captures `(int` and the cast slips through the
 * gate — which is exactly how the first version of this check was defeated.
 *
 * @return array<int,string>
 */
function whereArgExpressions(string $src): array
{
    $out = [];
    $needle = '->where(';
    $len = strlen($src);
    $offset = 0;

    while (($pos = strpos($src, $needle, $offset)) !== false) {
        $start = $pos + strlen($needle);
        $depth = 1;
        $i = $start;

        while ($i < $len && $depth > 0) {
            $ch = $src[$i];
            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth--;
            }
            $i++;
        }

        $out[] = substr($src, $start, max(0, $i - $start - 1));
        $offset = max($i, $pos + 1);
    }

    return $out;
}

/**
 * The body of every Laravel custom-rule closure (`function (…, $value, Closure $fail)`),
 * extracted with a brace-balanced scan so a guard elsewhere in the file cannot vouch for it.
 *
 * @return array<int,string>
 */
function ruleClosureBodies(string $src): array
{
    $out = [];
    $len = strlen($src);
    $offset = 0;

    while (($pos = strpos($src, 'Closure $fail', $offset)) !== false) {
        $open = strpos($src, '{', $pos);

        if ($open === false) {
            break;
        }

        $depth = 1;
        $i = $open + 1;

        while ($i < $len && $depth > 0) {
            $ch = $src[$i];
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
            }
            $i++;
        }

        $out[] = substr($src, $open, $i - $open);
        $offset = max($i, $pos + 1);
    }

    return $out;
}

/** Does this `where()` argument list key an FK on unclamped, client-supplied state? */
function whereLeaksClientScope(string $args): bool
{
    // First arg must be a quoted FK column; the rest is the value expression.
    if (! preg_match('/^\s*[\'"](\w*_id)[\'"]\s*,(.*)$/s', $args, $m)) {
        return false;
    }

    $value = $m[2];

    // `$get('…')` / `$g("…")` — any closure param invoked with a field name, i.e. a read of
    // client-supplied form state. Catches casts, `?? null`, trailing commas and renamed
    // params, each of which defeated the pin-one-spelling first cut.
    $readsClientState = (bool) preg_match('/\$\w+\s*\(\s*[\'"]/', $value);

    return $readsClientState && ! str_contains($value, 'clamp');
}

/* ---- structural: no unique rule may key on a raw client value ---------- */

it('has no unique rule keyed on a raw client-supplied scope', function () {
    $offenders = [];

    foreach (filamentSources() as $path) {
        $src = sourceWithoutComments($path);

        // Only files that actually build a unique rule can offend. Matches `->unique(`
        // and `Rule::unique(` alike.
        if (! preg_match('/unique\s*\(/i', $src)) {
            continue;
        }

        foreach (whereArgExpressions($src) as $args) {
            if (whereLeaksClientScope($args)) {
                $offenders[] = str_replace(base_path().'/', '', $path)." — where({$args})";
            }
        }
    }

    expect($offenders)->toBe([]);
});

/* ---- structural: custom rule closures leak through pass/fail too ------- */

it('has no custom rule closure querying on a raw client value', function () {
    // `Rule::unique` is not the only shape. Any validation that QUERIES on a client value
    // tells through its outcome — LeaseForm's "does this unit already have an active
    // lease?" answered "is that unit occupied?" for an invisible property.
    //
    // Narrow by construction: the Laravel custom-rule signature (`Closure $fail`) appears
    // in exactly two Filament files, so this stays high-signal rather than noisy.
    $offenders = [];

    foreach (filamentSources() as $path) {
        $src = sourceWithoutComments($path);

        if (! str_contains($src, 'Closure $fail')) {
            continue;
        }

        // Check the CLOSURE BODY, not the file. A file-level str_contains would pass
        // LeaseForm on the strength of an unrelated visibleAssetIds() in its options
        // closure — the same vacuous-substring flaw that made the first gate green while
        // the leak was live. (Caught by reverting the fix and watching this stay green.)
        foreach (ruleClosureBodies($src) as $body) {
            // ANY variable, not just $value: `$unitId = $value; where('unit_id', $unitId)`
            // is the same leak one hop removed, and pinning `$value` missed it — the same
            // indirection that defeated the first gate. Tracking dataflow properly is
            // beyond a static check, so this errs toward flagging: a rule closure that
            // queries an FK must visibly clamp or check visibility. Only two such closures
            // exist in the whole panel, so the strictness costs nothing.
            $queriesFk = (bool) preg_match('/where\(\s*[\'"]\w*_id[\'"]\s*,\s*\$\w+\s*[,)]/', $body);
            $isGuarded = str_contains($body, 'clamp') || str_contains($body, 'visibleAssetIds');

            if ($queriesFk && ! $isGuarded) {
                $offenders[] = str_replace(base_path().'/', '', $path);
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('cannot be fooled by the spellings that defeated the first gate', function () {
    // The previous regex pinned one exact spelling and missed 8 of 12 realistic variants.
    // Re-run the live check's logic over each so a future edit can't silently narrow it.
    $offending = [
        'baseline' => "->unique(modifyRuleUsing: fn (Unique \$rule, Get \$get) => \$rule->where('asset_id', \$get('asset_id')))",
        'double quotes' => '->unique(modifyRuleUsing: fn ($rule, $get) => $rule->where("asset_id", $get("asset_id")))',
        'int cast' => "->unique(modifyRuleUsing: fn (\$rule, \$get) => \$rule->where('asset_id', (int) \$get('asset_id')))",
        'null coalesce' => "->unique(modifyRuleUsing: fn (\$rule, \$get) => \$rule->where('asset_id', \$get('asset_id') ?? null))",
        'trailing comma' => "->unique(modifyRuleUsing: fn (\$rule, \$get) => \$rule->where('asset_id', \$get('asset_id'),))",
        'renamed param' => "->unique(modifyRuleUsing: fn (\$rule, \$g) => \$rule->where('asset_id', \$g('asset_id')))",
        'Rule::unique builder' => "->rules([Rule::unique('units')->where('asset_id', \$get('asset_id'))])",
        'lease scope' => "->unique(modifyRuleUsing: fn (\$rule, \$get) => \$rule->where('lease_id', \$get('lease_id')))",
    ];

    $safe = [
        'clamped asset' => "->unique(modifyRuleUsing: fn (\$rule, \$get) => \$rule->where('asset_id', TenantScope::clampAssetId(\$get('asset_id'))))",
        'clamped lease' => "->unique(modifyRuleUsing: fn (\$rule, \$get) => \$rule->where('lease_id', Portal::clampLeaseId(\$get('lease_id'))))",
        'server-side owner' => "->unique(modifyRuleUsing: fn (\$rule) => \$rule->where('utility_meter_id', \$this->ownerRecord->id))",
    ];

    // Exercise the SAME helpers the live check uses — not a copy that could drift from it.
    $flags = fn (string $snippet): bool => collect(whereArgExpressions($snippet))
        ->contains(fn (string $args) => whereLeaksClientScope($args));

    foreach ($offending as $label => $snippet) {
        expect($flags($snippet))->toBeTrue("the gate must flag: {$label}");
    }

    foreach ($safe as $label => $snippet) {
        expect($flags($snippet))->toBeFalse("the gate must not flag: {$label}");
    }
});

it('is not satisfied by a comment that merely mentions the clamp', function () {
    // The first gate's positive half was `str_contains($src, 'clampAssetId')` — every form
    // carries a comment naming the clamp, so deleting the scoping entirely left it green.
    $path = app_path('Filament/Admin/Resources/Warehouses/Schemas/WarehouseForm.php');

    expect(file_get_contents($path))->toContain('clampAssetId');   // the comment + the call
    expect(sourceWithoutComments($path))->toContain('clampAssetId'); // the call survives alone
});

/* ---- behavioural: the clamps themselves -------------------------------- */

it('clamps an out-of-scope asset id to null and passes a visible one through', function () {
    $mine = makeAsset(['code' => 'CLA']);
    $theirs = makeAsset(['code' => 'CLB']);
    $this->actingAs(makeUser('operations', [$mine->id]));

    asTenant(ensureAllPropertiesAsset(), function () use ($mine, $theirs) {
        expect(TenantScope::clampAssetId($mine->id))->toBe($mine->id);
        expect(TenantScope::clampAssetId($theirs->id))->toBeNull();
        expect(TenantScope::clampAssetId(null))->toBeNull();
        expect(TenantScope::clampAssetId(''))->toBeNull();
    });
});

it('passes any property through for an unrestricted user', function () {
    // visibleAssetIds() === null means UNRESTRICTED — the clamp must not become a second,
    // accidental authorization layer for super_admin.
    $a = makeAsset(['code' => 'CLC']);
    $this->actingAs(makeUser('super_admin'));

    asTenant(ensureAllPropertiesAsset(), function () use ($a) {
        expect(TenantScope::clampAssetId($a->id))->toBe($a->id);
    });
});

it('clamps a lease in an invisible property to null', function () {
    $mine = makeAsset(['code' => 'CLF']);
    $theirs = makeAsset(['code' => 'CLG']);
    $ours = makeLease(makeUnit($mine));
    $foreign = makeLease(makeUnit($theirs));

    $this->actingAs(makeUser('leasing', [$mine->id]));

    asTenant(ensureAllPropertiesAsset(), function () use ($ours, $foreign) {
        expect(TenantScope::clampLeaseId($ours->id))->toBe($ours->id);
        expect(TenantScope::clampLeaseId($foreign->id))->toBeNull();
    });
});

it('clamps another tenant\'s lease to null in the portal', function () {
    $asset = makeAsset(['code' => 'CLH']);
    $mine = makeTenant();
    $theirs = makeTenant();
    $ourLease = makeLease(makeUnit($asset), $mine);
    $theirLease = makeLease(makeUnit($asset), $theirs);

    $this->actingAs(makeTenantUser($mine, isAdmin: true), 'portal');

    expect(Portal::clampLeaseId($ourLease->id))->toBe($ourLease->id);
    expect(Portal::clampLeaseId($theirLease->id))->toBeNull();
});

/* ---- end-to-end on the most sensitive instance ------------------------- */

it('does not leak whether an employee code exists in an invisible property', function () {
    $mine = makeAsset(['code' => 'CLD']);
    $theirs = makeAsset(['code' => 'CLE']);

    Employee::create([
        'asset_id' => $theirs->id, 'code' => 'SECRET-EMP-1', 'name' => 'Hidden Person',
        'position' => 'Manager', 'hire_date' => '2025-01-01', 'base_salary' => 10000, 'status' => 'active',
    ]);

    $this->actingAs(makeUser('hr', [$mine->id])); // employees.* but assigned to `mine` only

    asTenant(ensureAllPropertiesAsset(), function () use ($theirs) {
        $errorsFor = fn (string $code) => array_keys(
            Livewire::test(CreateEmployee::class)
                ->fillForm([
                    'asset_id' => $theirs->id, // tampered
                    'code' => $code,
                    'name' => 'Probe',
                    'position' => 'Probe',
                    'hire_date' => '2026-01-01',
                    'base_salary' => 1000,
                    'status' => 'active',
                ])
                ->call('create')
                ->errors()
                ->toArray()
        );

        // A real code and a nonsense one must be indistinguishable.
        expect($errorsFor('SECRET-EMP-1'))->toBe($errorsFor('NOPE-XYZ'));
    });
});
