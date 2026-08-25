<?php

/*
|--------------------------------------------------------------------------
| A picker never prints its own placeholder as a message (2026-08-25)
|--------------------------------------------------------------------------
| Reported from the panel twice, on the payment form's Bank Account picker: the dropdown showed two
| identical boxes reading "Name, code or number…", which looks exactly like a broken duplicate
| field. It was neither a duplicate nor a render fault.
|
| **Filament assigns `searchPrompt` to the search input's own placeholder** (`select.js`:
| `this.searchInput.placeholder = this.searchPrompt`). `EntitySelect`'s suggest branch ALSO passed
| that same string as `noOptionsMessage` — the reasoning being that "nothing to suggest yet" is an
| invitation to type. The invitation was right; the string was not. The rendered page carried
| `noOptionsMessage: 'Name, code or number…'` beside `searchPrompt: 'Name, code or number…'`.
|
| Two states, two sentences, because they call for opposite actions:
|   nothing SUGGESTED (type — search reaches further)   → "No suggestions yet — type to search …"
|   nothing AT ALL    (searching cannot help)           → "No Bank Accounts yet"
|
| Asserted on the REAL rendered page rather than on the component, because the duplication only
| exists once Filament has written both values into the Alpine config — reading the object would
| have shown two correct-looking properties.
*/

use App\Filament\Admin\Resources\Payments\PaymentResource;
use App\Models\BankAccount;
use App\Support\Search\OptionDisplay;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->asset = makeAsset();
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/**
 * Every select's Alpine config on the page, keyed by the field it belongs to.
 *
 * @return array<string, array{noOptionsMessage: string, searchPrompt: string}>
 */
function selectConfigs(string $html): array
{
    preg_match_all("/statePath: '([^']*)'/", $html, $paths);
    preg_match_all("/noOptionsMessage: '([^']*)'/", $html, $noOptions);
    preg_match_all("/searchPrompt: '([^']*)'/", $html, $prompts);

    $configs = [];

    foreach ($paths[1] as $i => $path) {
        if (! isset($noOptions[1][$i], $prompts[1][$i])) {
            continue;
        }

        $configs[$path] = [
            'noOptionsMessage' => $noOptions[1][$i],
            'searchPrompt' => $prompts[1][$i],
        ];
    }

    return $configs;
}

it('does not render the search placeholder a second time as a message', function () {
    $html = $this->get(PaymentResource::getUrl('create', tenant: $this->asset))->getContent();
    $configs = selectConfigs($html);

    // The sweep must have found selects before it can report on them.
    expect($configs)->not->toBeEmpty();

    $duplicated = [];

    foreach ($configs as $path => $config) {
        if ($config['noOptionsMessage'] === $config['searchPrompt']) {
            $duplicated[] = $path.' → "'.$config['searchPrompt'].'"';
        }
    }

    expect($duplicated)->toBe([], 'These pickers print their own placeholder twice: '.implode(' · ', $duplicated));
});

it('names the record type when there is genuinely nothing', function () {
    $html = $this->get(PaymentResource::getUrl('create', tenant: $this->asset))->getContent();
    $bank = selectConfigs($html)['data.bank_account_id'] ?? null;

    // "No options" leaves the operator unable to tell a broken picker from an empty register.
    expect($bank)->not->toBeNull()
        ->and($bank['noOptionsMessage'])->toBe(OptionDisplay::emptyMessage(BankAccount::class))
        ->and($bank['noOptionsMessage'])->toContain('Bank Accounts');
});

it('invites a search instead, once the register has rows', function () {
    // Suggested and searchable are different sets, and the message has to say which one came back
    // empty — telling someone to search a register that holds nothing wastes their time, and
    // telling them nothing exists when it is one keystroke away is worse.
    $suggests = OptionDisplay::noSuggestionsMessage(BankAccount::class);

    expect($suggests)->not->toBe(OptionDisplay::emptyMessage(BankAccount::class))
        ->and($suggests)->not->toBe(OptionDisplay::searchPrompt(BankAccount::class))
        ->and($suggests)->toContain('Bank Accounts');
});

it('says both in Arabic, and actually in Arabic', function () {
    app()->setLocale('ar');

    $empty = OptionDisplay::emptyMessage(BankAccount::class);
    $suggests = OptionDisplay::noSuggestionsMessage(BankAccount::class);

    app()->setLocale('en');

    expect(preg_match('/\p{Arabic}/u', $empty))->toBe(1)
        ->and(preg_match('/\p{Arabic}/u', $suggests))->toBe(1)
        ->and($empty)->not->toBe($suggests);
});
