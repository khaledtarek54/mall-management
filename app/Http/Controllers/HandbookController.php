<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serve the built visual handbook at `/handbook`, to signed-in staff only.
 *
 * **Why it is not in public/.** nginx serves anything under the webroot directly, without PHP ever
 * running — so a build dropped in `public/handbook` is world-readable at a guessable URL, whatever
 * middleware the app declares. The handbook documents Eltizam's posting rules, GL account mappings,
 * approval ladders and internal controls. None of that is secret in the sense a password is, and
 * none of it belongs on the open internet either. So it builds to `storage/app/handbook` (outside
 * the webroot) and is read back through this route, where `auth` actually applies.
 *
 * **Path traversal is the whole risk here**, because the URL segment is a filesystem path by
 * design. `..%2f..%2f.env` is the attack, and the guard is not a string check on the input: it is
 * `realpath()` on the RESOLVED file, compared against `realpath()` of the build directory. A string
 * check can be defeated by encodings and symlinks; a resolved-prefix check cannot, and it fails
 * closed when the file does not exist.
 */
class HandbookController extends Controller
{
    /**
     * VitePress emits a fixed set of asset types. Anything not listed is refused rather than
     * guessed at: this route reads from disk, so an open-ended content-type map is how a stray
     * file becomes a download.
     */
    private const TYPES = [
        'html' => 'text/html; charset=UTF-8',
        'js' => 'application/javascript; charset=UTF-8',
        'css' => 'text/css; charset=UTF-8',
        'json' => 'application/json; charset=UTF-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'map' => 'application/json; charset=UTF-8',
    ];

    public function __invoke(Request $request, string $path = ''): Response|BinaryFileResponse
    {
        $root = realpath((string) config('handbook.root'));

        // Not built yet. Say so rather than 404-ing, because "the handbook is missing" and "that
        // page does not exist" send whoever sees it to completely different places — and the cause
        // is almost always a deploy that skipped `npm run docs:build`.
        if ($root === false) {
            abort(503, 'The handbook has not been built. Run `npm run docs:build` as part of the deploy.');
        }

        $file = $this->resolve($root, $path);

        abort_if($file === null, 404);

        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $type = self::TYPES[$extension] ?? null;

        abort_if($type === null, 404);

        return response()->file($file, [
            'Content-Type' => $type,
            // The build is content-hashed for assets but not for HTML, so let a page revalidate
            // while the fingerprinted assets behind it cache hard.
            'Cache-Control' => $extension === 'html' ? 'no-cache' : 'public, max-age=31536000, immutable',
            // It is internal documentation; keep it out of search engines even if the login ever
            // becomes optional on someone's staging box.
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    /**
     * Resolve a URL path to a real file INSIDE the build directory, or null.
     *
     * Directory URLs resolve to their `index.html`, which is what makes `/handbook/`,
     * `/handbook/money/` and `/handbook/ar/` work — VitePress's `cleanUrls` also means
     * `/handbook/map` must find `map.html`.
     */
    private function resolve(string $root, string $path): ?string
    {
        $path = trim($path, '/');

        $candidates = $path === ''
            ? ['index.html']
            : [$path, "{$path}.html", "{$path}/index.html"];

        foreach ($candidates as $candidate) {
            $resolved = realpath($root.DIRECTORY_SEPARATOR.$candidate);

            // realpath() collapses `..` and follows symlinks, so this compares where the request
            // ACTUALLY lands — not what it claimed. The separator on the prefix matters: without
            // it, a sibling directory named `handbook-secrets` would pass a bare `str_starts_with`.
            if ($resolved !== false
                && is_file($resolved)
                && str_starts_with($resolved, $root.DIRECTORY_SEPARATOR)) {
                return $resolved;
            }
        }

        return null;
    }
}
