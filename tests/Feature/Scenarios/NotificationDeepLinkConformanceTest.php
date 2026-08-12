<?php

/*
|--------------------------------------------------------------------------
| Conformance gate — every bell notification declares where it goes
|--------------------------------------------------------------------------
| App\Support\NotificationTargets is the single register of what an operator
| or a tenant lands on when they click a notification. This gate is what stops
| it drifting back into thirty-six independent decisions:
|
|   A. a notification class that ships with a toDatabase() and no row in the
|      registry fails the build — the same self-enforcing shape as
|      PropertyIsolationConformanceTest and GlRegistryConformanceTest;
|   B. a row naming a payload key the notification does not emit fails, so
|      renaming `invoice_id` cannot silently produce link-less notifications;
|   C. **a row pointing a panel at a resource that panel does not register
|      fails.** This is the one that matters. Both panels host an
|      InvoiceResource; both answer getUrl(); nothing at runtime objects to
|      handing a tenant the admin one. The registry is where that is decided,
|      so the registry is where it is checked;
|   D. every notification emits `format => filament`, without which Filament's
|      bell — which queries exactly that — renders nothing at all. LowStock
|      shipped without it and wrote a row on every scan that no one could see.
|
| If this fails, fix App\Support\NotificationTargets — not the test.
*/

use App\Filament\Admin\Pages\NotificationCenter;
use App\Support\NotificationLink;
use App\Support\NotificationTargets;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Resources\Resource;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/** Every notification class shipped in app/Notifications (channels + concerns excluded). */
function notificationClasses(): array
{
    return collect(glob(app_path('Notifications/*.php')))
        ->map(fn (string $file): string => 'App\\Notifications\\'.basename($file, '.php'))
        ->filter(fn (string $class): bool => class_exists($class) && is_subclass_of($class, Notification::class))
        ->values()
        ->all();
}

/** The classes a panel actually registers — the authority on "does this belong here". */
function panelClasses(string $panel): array
{
    $filamentPanel = Filament::getPanel($panel);

    return array_merge(
        array_values($filamentPanel->getResources()),
        array_values($filamentPanel->getPages()),
    );
}

it('classifies every notification that reaches a bell', function () {
    $unclassified = collect(notificationClasses())
        ->filter(fn (string $class): bool => method_exists($class, 'toDatabase'))
        ->reject(fn (string $class): bool => NotificationTargets::isClassified($class))
        ->all();

    expect($unclassified)->toBe([],
        'These notifications write a bell entry but declare no destination. Add a row to '
        .'App\Support\NotificationTargets::TARGETS (or NOT_IN_BELL with a reason): '
        .implode(', ', array_map('class_basename', $unclassified)));
});

it('does not register a destination for a notification that never reaches a bell', function () {
    foreach (NotificationTargets::registered() as $class) {
        expect(class_exists($class))->toBeTrue("{$class} is registered but does not exist");
        expect(method_exists($class, 'toDatabase'))->toBeTrue(
            class_basename($class).' declares a destination but writes no toDatabase() payload — '
            .'either it gained a destination it cannot use, or it lost the payload.'
        );
    }

    foreach (NotificationTargets::NOT_IN_BELL as $class => $reason) {
        expect(method_exists($class, 'toDatabase'))->toBeFalse(
            class_basename($class).' is registered as never reaching a bell, but it now writes a '
            .'toDatabase() payload. Move it to TARGETS and give it a destination.'
        );
        expect(trim($reason))->not->toBe('', "{$class} needs a stated reason, not an empty string");
    }
});

it('names a payload key the notification actually emits', function () {
    foreach (NotificationTargets::registered() as $class) {
        $record = NotificationTargets::record($class);

        if ($record === null) {
            continue;
        }

        [$model, $key] = $record;

        expect(class_exists($model))->toBeTrue("{$class} points at a model that does not exist: {$model}");

        $source = file_get_contents((new ReflectionClass($class))->getFileName());

        // The link resolves the record by reading this key out of the stored payload. A rename on
        // one side and not the other produces a notification that is silently unclickable.
        // `toContain` is variadic over NEEDLES, not (needle, message) — a message passed there is
        // silently searched for instead of reported. Hence the explicit boolean.
        expect(str_contains($source, "'{$key}' =>"))->toBeTrue(
            class_basename($class)." declares payload key '{$key}', but its toDatabase() never writes it.");
    }
});

it('never points one panel at the other panel\'s screens', function () {
    // The whole reason the registry exists. `/admin` is tenanted and `/portal` is not; a resource
    // registered on one panel has no route on the other, so a crossed wire is a 404 for a reader
    // who has done nothing wrong.
    foreach (['admin', 'portal'] as $panel) {
        $registered = panelClasses($panel);

        foreach (NotificationTargets::registered() as $class) {
            $destination = NotificationTargets::destination($class, $panel);

            if ($destination === null) {
                continue;
            }

            [$target] = $destination;

            expect(in_array($target, $registered, true))->toBeTrue(
                class_basename($class).' sends '.$panel.' readers to '.class_basename($target)
                .", which the {$panel} panel does not register. That link 404s.");
        }
    }
});

it('resolves every declared destination to a URL inside its own panel', function () {
    // Structural equality is not enough on its own: a resource can be registered and still fail to
    // produce a URL (no index page, a nested parent). Build one and read the path back.
    $asset = makeAsset(['code' => 'GATE01']);

    foreach (['admin' => '/admin/GATE01/', 'portal' => '/portal/'] as $panel => $prefix) {
        foreach (NotificationTargets::registered() as $class) {
            $destination = NotificationTargets::destination($class, $panel);

            if ($destination === null) {
                continue;
            }

            [$target] = $destination;

            $url = is_subclass_of($target, Page::class)
                ? $target::getUrl(panel: $panel, tenant: $panel === 'admin' ? $asset : null)
                : $target::getUrl('index', panel: $panel, tenant: $panel === 'admin' ? $asset : null);

            expect(str_contains($url, $prefix))->toBeTrue(
                class_basename($class)."'s {$panel} destination built [{$url}], which is not a {$panel} URL.");

            // A portal URL carrying a property slug is the admin shape wearing the wrong prefix.
            if ($panel === 'portal') {
                expect($url)->not->toContain('GATE01');
            }
        }
    }
});

it('declares a hop only to a relation that exists', function () {
    foreach (['admin', 'portal'] as $panel) {
        foreach (NotificationTargets::registered() as $class) {
            $destination = NotificationTargets::destination($class, $panel);
            $hop = $destination[1] ?? null;

            if ($hop === null) {
                continue;
            }

            $record = NotificationTargets::record($class);
            expect($record)->not->toBeNull(class_basename($class).' hops from a record it does not declare');

            // A typo'd relation returns null and looks exactly like "no link available" — the same
            // trap DeletionPolicy's blocked_by check exists to close.
            expect(method_exists($record[0], $hop))->toBeTrue(
                class_basename($class)." hops to '{$hop}', which ".class_basename($record[0]).' does not define.');
        }
    }
});

it('states a reason when a notification is given no destination on either panel', function () {
    foreach (NotificationTargets::TARGETS as $class => $spec) {
        if (($spec['admin'] ?? null) !== null || ($spec['portal'] ?? null) !== null) {
            continue;
        }

        expect(trim((string) ($spec['why'] ?? '')))->not->toBe('',
            class_basename($class).' has no destination on either panel and no stated reason. If that '
            .'is deliberate, say why — otherwise it reads as a forgotten row.');
    }
});

it('tags every bell payload with the format Filament actually queries', function () {
    // Filament's bell reads `where('data->format', 'filament')` and renders nothing else. A payload
    // without it is written on every scan and seen by no one — which is what LowStockNotification
    // did, under a docblock that said "bell only".
    $untagged = [];

    foreach (NotificationTargets::registered() as $class) {
        $source = file_get_contents((new ReflectionClass($class))->getFileName());

        if (! str_contains($source, "'format' => 'filament'")) {
            $untagged[] = class_basename($class);
        }
    }

    expect($untagged)->toBe([],
        "These notifications write a bell payload Filament's bell can never render (no "
        ."`'format' => 'filament'`): ".implode(', ', $untagged));
});

it('leaves no notification carrying the dead `url` key', function () {
    // Six payloads carried `'url' => null` that nothing ever read. The destination now lives in the
    // registry; a reappearing `url` key is someone re-solving a solved problem in the wrong place.
    $offenders = collect(notificationClasses())
        ->filter(fn (string $class): bool => str_contains(
            file_get_contents((new ReflectionClass($class))->getFileName()),
            "'url' =>"
        ))
        ->map('class_basename')
        ->values()
        ->all();

    expect($offenders)->toBe([],
        'These notifications hand-write a `url` payload key. Destinations belong in '
        .'App\Support\NotificationTargets: '.implode(', ', $offenders));
});

it('exposes the permission module for every admin resource it links to', function () {
    // NotificationLink asks the RECIPIENT whether they may open the link, because there is no
    // session to ask. That needs the resource's permission module to be readable from outside.
    foreach (NotificationTargets::registered() as $class) {
        $destination = NotificationTargets::destination($class, 'admin');

        if ($destination === null || is_subclass_of($destination[0], Page::class)) {
            continue;
        }

        /** @var class-string<resource> $target */
        $target = $destination[0];

        expect(method_exists($target, 'permissionModuleKey'))->toBeTrue(
            class_basename($target).' is a notification destination but does not expose its '
            .'permission module, so a link to it cannot be authorised against the recipient.');

        expect(Str::of($target::permissionModuleKey())->trim()->value())->not->toBe('',
            class_basename($target).' resolves an empty permission module.');
    }
});

it('sends readers of both panels to a notification centre that exists', function () {
    // Every notification without a record falls back here, so a missing page turns the fallback
    // into the dead end it was meant to remove.
    expect(panelClasses('admin'))->toContain(NotificationCenter::class);
    expect(panelClasses('portal'))->toContain(App\Filament\Portal\Pages\NotificationCenter::class);

    $asset = makeAsset(['code' => 'GATE02']);
    $operator = makeUser('manager', [$asset->id]);
    NotificationLink::flushCache();

    expect(NotificationLink::centre($operator))->toContain('/admin/GATE02/notifications');
    expect(NotificationLink::centre(makeTenantUser(makeTenant())))->toContain('/portal/notifications');
});
