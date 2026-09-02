<?php

namespace App\Support\Assistant;

use App\Support\Search\SearchText;

/**
 * WHICH documentation the assistant may quote to an operator, and why the rest is excluded.
 *
 * ## The whole point is that `docs/` is not one audience
 *
 * `docs/modules/` is 1.77 MB written for whoever changes the code — "`LedgerPoster::JOURNALIZERS`
 * is the single registry of what posts to the GL". Indexing it would answer a retail manager
 * asking how to raise a credit note with a paragraph about a PHP class. That is not a thin answer,
 * it is a WRONG one: it reads as though the system has no explanation for a business act, only an
 * implementation. So the sources are an explicit allowlist, and every top-level directory under
 * `docs/` is either indexed here or excluded with a reason — `AssistantDocSourcesConformanceTest`
 * fails the build on one that is neither, so a new documentation area forces the decision rather
 * than silently defaulting to invisible.
 *
 * ## Two kinds of source, and the difference is whether a reader can open it
 *
 * `docs/visual/` is the handbook — published at `/handbook`, bilingual, one built HTML page per
 * markdown file — so its chunks carry a real URL and the excerpt is a preview of somewhere to go.
 * The training walkthroughs are repository files served nowhere, so their excerpt IS the answer.
 * Both are useful; conflating them would either invent a link that 404s or hide the only text that
 * answers the question.
 */
final class DocCorpus
{
    /**
     * Indexed, with the reason each is operator-facing.
     *
     * `url` is the public prefix a file maps to, or null when the source is not published.
     *
     * @var array<string, array{reason: string, url: string|null}>
     */
    public const SOURCES = [
        'visual' => [
            'reason' => 'The visual handbook — written for an operator, bilingual, and already published at /handbook, so every chunk has somewhere to send the reader.',
            'url' => '/handbook',
        ],
        'training' => [
            'reason' => 'The people-facing walkthroughs. Its own README says it is "written for someone new to the BUSINESS — not to the codebase", which is exactly this reader. Not published anywhere, so its excerpt is the answer rather than a pointer.',
            'url' => null,
        ],
    ];

    /**
     * Indexed ONLY when `assistant.index_technical_docs` is on.
     *
     * The default is off, and the reason is the same one that governs `SOURCES`: `docs/modules/`
     * explains the CODE — registries, invariants, class names — so quoting it to a retail manager
     * answers a business question with an implementation, which reads as though no business answer
     * exists.
     *
     * It is a SWITCH rather than a permanent exclusion because the same corpus is exactly right for
     * a different reader: a technical demo, or a team that is itself technical, where "how does the
     * GL decide which account to post to" is a real question with a real answer sitting in a file
     * nobody outside the repository can find.
     *
     * @var array<string, array{reason: string, url: string|null}>
     */
    public const TECHNICAL_SOURCES = [
        'modules' => [
            'reason' => 'The per-module reference: business rules, extension points and gotchas, written for whoever changes the code. Off by default because it answers an operator with an implementation; on for a technical audience, where it is the deepest and most accurate description of the system that exists.',
            'url' => null,
        ],
    ];

    /**
     * Deliberately NOT indexed at all. Each reason answers "why would an operator not want this".
     *
     * @var array<string, string>
     */
    public const NOT_INDEXED = [
        'accounting' => 'Mixed audience, and the operator-facing half (WALKTHROUGH, ACCOUNTANT-BRIEFING) is aimed at the ACCOUNTANT rather than the person at the panel. It also carries posting maps and tax-catalogue tables that mean nothing out of context. Revisit if the accountant ever gets their own reader.',
        'qa' => 'Test plans and findings. About whether the software works, not about how the business runs.',
        'benchmarks' => 'Notes on Yardi, MRI and the FM specialists — how OTHER systems behave. Quoting it would describe a screen this system does not have.',
        'gap-analysis' => 'What is missing. Answering "how do I do X" with "we have not built X" is right only when true, and this document is about the roadmap rather than the build — it would be wrong more often than right.',
        'operations' => 'Deploys, staging, infrastructure. For whoever runs the servers, not whoever runs the mall.',
        'integrations' => 'Provider setup and credentials, including this assistant\'s own design. Configuration work, and none of it is an answer to a question typed into the panel.',
        'api' => 'The mobile API contract and its generated OpenAPI spec — written for the app team building against this system, not for anyone using it. Endpoints and payload shapes are not an answer to any question asked from the panel.',
        'requirements' => 'The client\'s own words, kept verbatim as a source document. It states what was ASKED for, which is not the same as what the system does — and where they differ, quoting it would be a promise the panel cannot keep.',
    ];

    /** Root-level markdown files worth indexing, with the same rule applied. */
    public const ROOT_FILES = [
        'BUSINESS-RULES.md' => 'The rules register the operator and their accountant sign off — VAT treatment, late fees, proration. It is addressed to them by name.',
    ];

    public const MIN_CHUNK_LENGTH = 120;

    public const EXCERPT_LENGTH = 400;

    /**
     * Every chunk, ready to be written.
     *
     * @return array<int, array{path: string, locale: string, heading: string, url: string|null, excerpt: string, search_blob: string}>
     */
    public static function chunks(string $docsPath): array
    {
        $chunks = [];

        foreach (self::files($docsPath) as $relative => $urlPrefix) {
            $absolute = $docsPath.'/'.$relative;

            if (! is_file($absolute)) {
                continue;
            }

            foreach (self::split((string) file_get_contents($absolute)) as $section) {
                if (mb_strlen($section['body']) < self::MIN_CHUNK_LENGTH) {
                    continue;
                }

                $chunks[] = [
                    'path' => $relative,
                    'locale' => self::localeOf($relative),
                    'heading' => mb_substr($section['heading'], 0, 250),
                    'url' => self::urlFor($relative, $urlPrefix),
                    'excerpt' => mb_substr(self::plain($section['body']), 0, self::EXCERPT_LENGTH),
                    // The heading is folded into the blob TWICE over — once here and once as its
                    // own scoring signal at query time. Cheap, and it means a question phrased as a
                    // heading matches even when the body never repeats the words.
                    'search_blob' => SearchText::blob([$section['heading'], self::plain($section['body'])]),
                ];
            }
        }

        return $chunks;
    }

    /**
     * The indexed files, mapped to their published prefix.
     *
     * @return array<string, string|null> relative path => url prefix or null
     */
    public static function files(string $docsPath): array
    {
        $files = [];

        $sources = self::SOURCES;

        if (config('assistant.index_technical_docs')) {
            $sources += self::TECHNICAL_SOURCES;
        }

        foreach ($sources as $directory => $meta) {
            $base = $docsPath.'/'.$directory;

            if (! is_dir($base)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));

            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'md') {
                    continue;
                }

                $relative = $directory.'/'.ltrim(str_replace($base, '', $file->getPathname()), '/');
                $files[$relative] = $meta['url'];
            }
        }

        foreach (array_keys(self::ROOT_FILES) as $file) {
            $files[$file] = null;
        }

        // Sorted so a rebuild writes the same rows in the same order — a diff of two rebuilds
        // should show what CHANGED, not what the filesystem happened to hand back first.
        ksort($files);

        return $files;
    }

    /**
     * Split markdown into level-2 sections.
     *
     * Level 2 because it is the unit these documents are actually written in — a `##` in the
     * receivables walkthrough is one business act. Anything before the first `##` becomes a chunk
     * under the document's own `#` title, so an introduction is not lost.
     *
     * @return array<int, array{heading: string, body: string}>
     */
    public static function split(string $markdown): array
    {
        $lines = preg_split('/\R/', $markdown) ?: [];
        $sections = [];
        $heading = '';
        $body = [];
        $inFence = false;

        foreach ($lines as $line) {
            // A `##` inside a fenced block is a comment or a shell prompt, not a heading. Without
            // this the code samples in these documents split a section in half.
            if (str_starts_with(ltrim($line), '```')) {
                $inFence = ! $inFence;
            }

            if (! $inFence && preg_match('/^##\s+(.+)$/', $line, $m)) {
                $sections[] = ['heading' => $heading, 'body' => implode("\n", $body)];
                $heading = trim($m[1]);
                $body = [];

                continue;
            }

            if (! $inFence && $heading === '' && preg_match('/^#\s+(.+)$/', $line, $m)) {
                $heading = trim($m[1]);

                continue;
            }

            $body[] = $line;
        }

        $sections[] = ['heading' => $heading, 'body' => implode("\n", $body)];

        return array_values(array_filter($sections, fn (array $s): bool => $s['heading'] !== ''));
    }

    /** `docs/visual/ar/...` is the Arabic handbook; everything else is English. */
    public static function localeOf(string $relative): string
    {
        return str_starts_with($relative, 'visual/ar/') ? 'ar' : 'en';
    }

    /**
     * `visual/leasing/lease-lifecycle.md` → `/handbook/leasing/lease-lifecycle.html`.
     *
     * The handbook is built by VitePress one HTML page per markdown file, so the mapping is
     * mechanical. An `index.md` becomes the directory itself.
     */
    public static function urlFor(string $relative, ?string $urlPrefix): ?string
    {
        if ($urlPrefix === null || ! str_starts_with($relative, 'visual/')) {
            return null;
        }

        $path = substr($relative, strlen('visual/'));
        $path = preg_replace('/\.md$/', '', $path) ?? $path;

        if ($path === 'index') {
            return $urlPrefix.'/';
        }

        if (str_ends_with($path, '/index')) {
            return $urlPrefix.'/'.substr($path, 0, -strlen('/index')).'/';
        }

        return $urlPrefix.'/'.$path.'.html';
    }

    /** Markdown reduced to the words a reader would actually read. */
    public static function plain(string $markdown): string
    {
        $text = preg_replace('/```.*?```/s', ' ', $markdown) ?? $markdown;
        $text = preg_replace('/!\[[^\]]*\]\([^)]*\)/', ' ', $text) ?? $text;
        $text = preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $text) ?? $text;
        $text = preg_replace('/[`*_>#|]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }
}
