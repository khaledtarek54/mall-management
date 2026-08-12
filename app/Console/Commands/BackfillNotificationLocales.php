<?php

namespace App\Console\Commands;

use App\Support\NotificationBellAction;
use App\Support\NotificationLink;
use App\Support\NotificationLocale;
use App\Support\NotificationTargets;
use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\App;
use ReflectionClass;

/**
 * **Teach the notifications that already exist to speak the reader's language.**
 *
 * `BellChannel` stores every alert in both languages, but only from the moment it shipped. Rows
 * written before that carry one rendered string each, in whichever locale happened to be current
 * when they were raised — so an operator who switches to Arabic sees a fully Arabic screen with an
 * English inbox on it, which reads as a broken feature rather than as an old row.
 *
 * There is no honest way to re-run the original `toDatabase()`: it needs the notification OBJECT,
 * whose constructor takes models that may since have changed or gone. So this works backwards from
 * the text instead:
 *
 *   1. every notification class declares which catalogue keys it renders — read them out of its
 *      own source, so the candidate set is exactly what that class could have produced;
 *   2. render each candidate in English and turn it into a pattern, with `:placeholder` becoming a
 *      capture;
 *   3. match the stored string. A hit gives back both the KEY and the values that were substituted
 *      into it;
 *   4. re-render that key, with those values, in every supported language.
 *
 * The captured values pass through untouched, which is the correct behaviour and not a shortcut: a
 * work order's title, a tenant's name and a document number are operator-entered data, and the
 * Arabic alert should quote them exactly as typed. Only the sentence around them is translated.
 *
 * Candidates are drawn from the row's OWN notification class, which is what makes the matching
 * safe — `:reference: :subject` would otherwise match almost anything, but it is only ever
 * considered for the notification that renders it.
 *
 * Dry-run by default; `--commit` writes. Same shape as `atriom:project-lease-schedules`.
 */
class BackfillNotificationLocales extends Command
{
    protected $signature = 'atriom:backfill-notification-locales
                            {--commit : Write the translations. Without this, only report what would change.}
                            {--refresh : Redo rows that already carry translations — after fixing the matcher, or rewording a catalogue string.}
                            {--chunk=200 : Rows per batch.}';

    protected $description = 'Add the per-language renderings to bell notifications written before they existed';

    /** @var array<class-string, array<int, string>> */
    private array $keyCache = [];

    /** @var array<class-string, array<int, string>> */
    private array $prefixCache = [];

    public function handle(): int
    {
        $commit = (bool) $this->option('commit');
        $matched = 0;
        $unmatched = 0;
        $skipped = 0;
        $linked = 0;
        $byType = [];

        $query = DatabaseNotification::query()->where('data->format', 'filament');

        if (! $this->option('refresh')) {
            $query->whereNull('data->'.NotificationLocale::KEY);
        }

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('Every bell notification already carries its translations. Nothing to do.');
            $this->line('(Use --refresh to redo them anyway — after fixing the matcher or rewording a string.)');

            return self::SUCCESS;
        }

        $this->line($this->option('refresh')
            ? "Re-deriving {$total} notification(s)."
            : "Found {$total} notification(s) written before the translations existed.");

        $query->chunkById((int) $this->option('chunk'), function ($rows) use ($commit, &$matched, &$unmatched, &$skipped, &$linked, &$byType) {
            foreach ($rows as $row) {
                $translations = $this->translationsFor($row);

                if ($translations === null) {
                    $skipped++;

                    continue;
                }

                [$i18n, $hit] = $translations;

                $hit ? $matched++ : $unmatched++;
                $byType[class_basename($row->type)][$hit ? 'matched' : 'unmatched'] ??= 0;
                $byType[class_basename($row->type)][$hit ? 'matched' : 'unmatched']++;

                $data = $row->data;
                $data[NotificationLocale::KEY] = $i18n;

                // These rows predate the deep links as well as the translations — the operator is
                // looking at the same entries either way, so backfilling one without the other
                // leaves half the work invisible on exactly the data in front of them. Never
                // overwrite an action a row already has.
                //
                // Computed OUTSIDE the commit guard on purpose: a dry run that cannot count what it
                // would do is worse than no dry run, because it is the thing people read before
                // trusting the write.
                if (blank($data['actions'] ?? null)) {
                    $action = $row->notifiable
                        ? NotificationBellAction::for($row->type, $row->notifiable, $data)
                        : null;

                    if ($action !== null) {
                        $data['actions'] = [$action];
                        $linked++;
                    }
                }

                if ($commit) {
                    // `saveQuietly` — a backfill is not an event worth waking anything for, and
                    // these rows are read-only history.
                    $row->forceFill(['data' => $data])->saveQuietly();
                }
            }
        });

        $this->newLine();
        $this->table(
            ['Notification', 'Translated', 'Left as written'],
            collect($byType)->map(fn (array $counts, string $type): array => [
                $type,
                $counts['matched'] ?? 0,
                $counts['unmatched'] ?? 0,
            ])->values()->all(),
        );

        $this->info("{$matched} translated · {$unmatched} kept as written · {$skipped} skipped (no class) · {$linked} given a link.");

        if (! $commit) {
            $this->warn('Dry run — nothing was written. Re-run with --commit.');
        }

        // An unmatched row is not a failure: an announcement's title IS its content, and a body
        // whose wording has since been reworded in the catalogue can no longer be recognised. Both
        // keep their stored text, which is what they showed before.
        return self::SUCCESS;
    }

    /**
     * @return array{array<string, array<string, string|null>>, bool}|null [translations, wasMatched]
     */
    private function translationsFor(DatabaseNotification $row): ?array
    {
        if (! class_exists($row->type)) {
            return null;
        }

        $data = $row->data;
        $panel = $this->panelOf($row);

        // An old row may have no action at all; the backfill is about to give it one, and the
        // label has to be written in every language alongside the text it sits under.
        $actionName = $data['actions'][0]['name']
            ?? (NotificationTargets::destination($row->type, (string) $panel) === null ? 'details' : 'open');

        $title = $this->resolve($row->type, (string) ($data['title'] ?? ''));
        $body = $this->resolve($row->type, (string) ($data['body'] ?? ''));

        $current = App::getLocale();
        $i18n = [];

        try {
            foreach (NotificationLocale::supported() as $locale) {
                App::setLocale($locale);

                $i18n[$locale] = [
                    'title' => $title
                        ? __($title['key'], $this->localizeTokens($row->type, $title['replace']))
                        : ($data['title'] ?? null),
                    'body' => $body
                        ? __($body['key'], $this->localizeTokens($row->type, $body['replace']))
                        : ($data['body'] ?? null),
                    'action_label' => match ($actionName) {
                        'open' => NotificationLocale::openLabel($row->type, $panel),
                        'details' => __('admin.notifications.actions.details'),
                        default => null,
                    },
                ];
            }
        } finally {
            App::setLocale($current);
        }

        return [$i18n, $title !== null || $body !== null];
    }

    /**
     * Which catalogue key produced this string, and with what substituted into it.
     *
     * @param  class-string  $notification
     * @return array{key: string, replace: array<string, string>}|null
     */
    private function resolve(string $notification, string $rendered): ?array
    {
        if ($rendered === '') {
            return null;
        }

        foreach ($this->keysFor($notification) as $key) {
            $english = __($key, [], 'en');

            if (! is_string($english) || $english === $key) {
                continue;
            }

            // Split on the placeholders so the literal parts can be escaped and the gaps captured.
            $parts = preg_split('/(:[a-z_]+)/', $english, -1, PREG_SPLIT_DELIM_CAPTURE);
            $pattern = '';
            $names = [];

            foreach ($parts as $part) {
                // A delimiter is a part that IS a placeholder, not one that merely starts like one.
                // `:reference: :subject` splits to [':reference', ': ', ':subject'], and that middle
                // LITERAL also starts with a colon — reading it as a placeholder turned the whole
                // pattern into `^(.*?)(.*?)(.*?)$`, a wildcard that matched every string it was
                // offered. It claimed titles belonging to other keys, which is exactly the
                // over-eager match this narrowing was supposed to prevent.
                if (preg_match('/^:[a-z_]+$/', $part)) {
                    $names[] = substr($part, 1);
                    $pattern .= '(.*?)';

                    continue;
                }

                $pattern .= preg_quote($part, '/');
            }

            // A pattern with no literal text at all cannot identify anything — it would match the
            // first string it was offered. Refuse it rather than trust it.
            //
            // This and the placeholder test above are REDUNDANT on the current catalogue: mutation
            // testing shows either one alone fixes the bug, and only removing both reproduces it
            // (three tests go red). They are kept because they refuse different things — one makes a
            // pattern accurate, the other refuses a pattern that cannot discriminate however
            // accurate it is — and a future key of the shape `:a: :b — something` would need the
            // first, while a future key that is nothing but placeholders would need the second.
            if (trim($pattern) === '' || $pattern === str_repeat('(.*?)', count($names))) {
                continue;
            }

            if (! preg_match('/^'.$pattern.'$/su', $rendered, $captures)) {
                continue;
            }

            array_shift($captures);

            return ['key' => $key, 'replace' => array_combine($names, $captures) ?: []];
        }

        return null;
    }

    /**
     * The catalogue PREFIXES a notification interpolates a column value into — e.g.
     * `__("admin.owner_requests.statuses.{$this->request->status}")`.
     *
     * Needed because the old payloads this backfills were written before those lookups existed:
     * `OwnerRequestNotification` used to interpolate `$request->status` raw, so the stored English
     * reads "OR-2026-0001 is now resolved." Re-rendering the sentence in Arabic without touching the
     * captured value gives «أصبح OR-2026-0001 الآن resolved» — an Arabic sentence with a database
     * token in it, which is the precise half-translated result this whole change exists to remove.
     *
     * @param  class-string  $notification
     * @return array<int, string>
     */
    private function enumPrefixes(string $notification): array
    {
        return $this->prefixCache[$notification] ??= (function () use ($notification): array {
            $file = (new ReflectionClass($notification))->getFileName();

            if (! $file || ! is_file($file)) {
                return [];
            }

            // Double-quoted keys ending in an interpolation: "admin.x.y.{$z}" -> "admin.x.y."
            preg_match_all('/"([a-z0-9_.]+\.)\{\$/i', (string) file_get_contents($file), $matches);

            return array_values(array_unique($matches[1]));
        })();
    }

    /**
     * Translate a captured value if it is a catalogue token rather than operator-entered data.
     *
     * A work order's title, a tenant's name and a reference are quoted exactly as typed — that is
     * the correct behaviour and the reason captures pass through untouched by default. A STATUS is
     * different: it is a token the system chose, and it has a label in both languages.
     *
     * @param  class-string  $notification
     * @param  array<string, string>  $replace
     * @return array<string, string>
     */
    private function localizeTokens(string $notification, array $replace): array
    {
        $prefixes = $this->enumPrefixes($notification);

        if ($prefixes === []) {
            return $replace;
        }

        foreach ($replace as $name => $value) {
            // Only a bare token can be one — anything with a space is prose or a name.
            if (! preg_match('/^[a-z][a-z0-9_]*$/', $value)) {
                continue;
            }

            foreach ($prefixes as $prefix) {
                $label = __($prefix.$value);

                if (is_string($label) && $label !== $prefix.$value) {
                    $replace[$name] = $label;

                    break;
                }
            }
        }

        return $replace;
    }

    /**
     * The catalogue keys a notification class renders, read out of its own source.
     *
     * Narrowing candidates to the row's own class is what makes this safe: a key like
     * `:reference: :subject` is a pattern that matches nearly anything, and would happily claim
     * another notification's body if every key in the catalogue were a candidate.
     *
     * @param  class-string  $notification
     * @return array<int, string>
     */
    private function keysFor(string $notification): array
    {
        return $this->keyCache[$notification] ??= (function () use ($notification): array {
            $file = (new ReflectionClass($notification))->getFileName();

            if (! $file || ! is_file($file)) {
                return [];
            }

            // EVERY dotted lowercase literal in the file, not just the ones directly after `__(`.
            // A third of the notifications choose their key inside the call —
            // `__($isPreventive ? 'admin.…ppm_title' : 'admin.…cm_title')` — so anchoring on `__('`
            // saw neither, and their titles came back untranslated while their bodies did not.
            // Non-catalogue matches (`lease.unit`) are harmless: `__()` returns them unchanged and
            // `resolve()` skips anything that resolves to itself.
            preg_match_all("/'([a-z0-9_]+(?:\.[a-z0-9_]+)+)'/i", (string) file_get_contents($file), $matches);

            // Longest first: the most specific pattern should claim the string before a looser one
            // built almost entirely of placeholders gets a chance at it.
            $keys = array_values(array_unique($matches[1]));
            usort($keys, fn (string $a, string $b): int => strlen((string) __($b, [], 'en')) <=> strlen((string) __($a, [], 'en')));

            return $keys;
        })();
    }

    /** The panel this row's reader signs into — the same mapping the links use. */
    private function panelOf(DatabaseNotification $row): ?string
    {
        return class_exists($row->notifiable_type)
            ? NotificationLink::panelFor(new $row->notifiable_type)
            : null;
    }
}
