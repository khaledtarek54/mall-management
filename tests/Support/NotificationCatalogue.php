<?php

namespace Tests\Support;

use Illuminate\Notifications\Notification;
use ReflectionClass;

/**
 * Test-side enumeration of the notification classes this project ships.
 *
 * A CLASS, not a file-scope `function` in one of the test files that needs it. Two conformance
 * tests ask this question — one about deep links, one about language — and Pest parallelises per
 * FILE: a worker loads only the test files it owns, so a helper declared in the other file is
 * simply absent, and declaring it in both is a fatal redeclaration during collection that exits
 * 255 with no output at all. That has happened three times in this repo already
 * (`makeViolation`, `optionOn`, `annualPctLease`) — see CLAUDE.md.
 */
final class NotificationCatalogue
{
    /**
     * Every notification class under app/Notifications. Channels and concerns live in
     * subdirectories and are excluded by the glob.
     *
     * @return array<int, class-string<Notification>>
     */
    public static function classes(): array
    {
        return collect(glob(app_path('Notifications/*.php')))
            ->map(fn (string $file): string => 'App\\Notifications\\'.basename($file, '.php'))
            ->filter(fn (string $class): bool => class_exists($class) && is_subclass_of($class, Notification::class))
            ->values()
            ->all();
    }

    /** The file a notification class is defined in. */
    public static function sourceOf(string $notification): string
    {
        return (string) file_get_contents((new ReflectionClass($notification))->getFileName());
    }

    /**
     * The body of one method, for the source-reading gates. Returns null when the class does not
     * declare it — a notification with no `toMail()` has no email prose to check.
     */
    public static function methodBody(string $notification, string $method): ?string
    {
        $source = self::sourceOf($notification);

        return preg_match('/function '.preg_quote($method, '/').'\(.*?\n    \}/s', $source, $match)
            ? $match[0]
            : null;
    }
}
