<?php

use App\Support\DocumentText;
use Illuminate\Support\Facades\File;

/**
 * **A message a TENANT reads is wording the operator owns** — EG-15.
 *
 * Yardi templates every tenant-facing notice, and for a reason a leasing manager would give rather
 * than a developer: these are the sentences a relationship is conducted in. A chasing email that
 * reads as a system alert gets ignored; a late-fee notice is the message most likely to be argued
 * with; the renewal opener is the first thing said about whether the tenant is staying. An operator
 * who cannot change those words is running someone else's tone with their own name on it.
 *
 * The seam is `App\Support\DocumentText`, resolving *property row → house row → the translation key
 * the notification always used*. The floor is what makes it deployable: an install that has written
 * nothing sends exactly what it sent before.
 *
 * ## Why a gate rather than a list
 *
 * Four notices were converted one at a time, and "fixed one instance, left the siblings" is this
 * codebase's most repeated defect — it has already produced a second copy of a lock, a search path,
 * a payment rail and an authorization seam. Discovering the notifications FROM DISK and requiring
 * each to be either templated or exempt-with-a-reason means the fifth one forces the decision
 * instead of inheriting the default.
 *
 * `EXEMPT` is not a waiver list to grow quietly. Each entry states why that message's wording is not
 * the operator's to own.
 */
const TENANT_WORDING_EXEMPT = [
    'TenantResetPasswordNotification' => 'A security email on a fixed flow. Operator-authored text on a password link is a phishing surface, not a tone decision — and Laravel owns the template.',
    'ChequeCoverageEndingNotification' => 'Operational prompt about lodged cheques running out — a working alert to the operator side of the relationship, not a standing notice a tenant reads as policy.',
    'SalesDeclarationReminderNotification' => 'A deadline nudge whose whole content is a date the system computes. Nothing here is a phrasing decision.',
    'SalesDeclarationLockedNotification' => 'States that a declaration is now locked — a fact about system state, not a message with a tone.',
    'TenantDocumentExpiringNotification' => 'Compliance chase on a document the tenant filed. Same shape as the sales reminder: a date and a document name.',
    'TenantRequestCommentAddedNotification' => 'Relays a comment somebody already wrote. The wording IS the comment.',
    'TenantRequestStatusChangedNotification' => 'Reports a status transition; the vocabulary is the request board\'s own, resolved from `admin.statuses`.',
    'LeaseOptionWindowNotification' => 'Fires to the OPERATOR about an option window opening, not to the tenant.',
    'WorkOrderSlaBreachedNotification' => 'Goes to the facility side about a job past its target, never to a tenant — it names one only in a docblock comparing it to the tenant-request equivalent. The sweep matches that word deliberately loosely, because over-inclusive forces a decision and under-inclusive hides one.',
    'WorkOrderResponseSlaBreachedNotification' => 'The response-clock twin of the breach notice above, and to the same audience: an operator chasing their own contractor, not a message the tenant ever sees.',
];

it('templates every tenant-facing mail notice, or says why not', function () {
    $notTemplated = [];
    $checked = 0;

    foreach (File::allFiles(app_path('Notifications')) as $file) {
        $code = $file->getContents();
        $name = $file->getFilenameWithoutExtension();

        // Only notifications that actually send MAIL. A bell row is resolved at READ time from
        // `admin.*` and is bilingual by construction; an email is composed once and sent.
        if (! str_contains($code, "'mail'")) {
            continue;
        }

        // …and only those a TENANT receives. An owner or an internal alert is the operator talking
        // to themselves, where a shipped sentence is the right default.
        if (! preg_match('/\b(tenant|Tenant)\b/', $code)) {
            continue;
        }

        $checked++;

        if (array_key_exists($name, TENANT_WORDING_EXEMPT)) {
            continue;
        }

        // A notification that renders a VIEW keeps its wording in the blade, not in the class —
        // `InvoiceIssuedNotification` is the one, and checking only the class here reported it as
        // un-templated when the covering note had just been templated one file away. Follow the
        // view: this gate is about whether the OPERATOR can change the words, and it does not
        // matter which file they live in.
        $templated = str_contains($code, 'DocumentText::for(');

        if (! $templated && preg_match_all("/->(?:markdown|view)\(\s*'([a-z0-9_.\-]+)'/i", $code, $views)) {
            foreach ($views[1] as $view) {
                $path = resource_path('views/'.str_replace('.', '/', $view).'.blade.php');

                if (File::exists($path) && str_contains(File::get($path), 'DocumentText::for(')) {
                    $templated = true;
                    break;
                }
            }
        }

        if (! $templated) {
            $notTemplated[] = $name;
        }
    }

    // The sweep must have found something before it reports on nothing — the vacuity trap this
    // codebase has hit three times, most memorably a gate that swept zero models for a year.
    expect($checked)->toBeGreaterThan(8);

    expect($notTemplated)->toBe([], implode("\n", [
        'These notifications email a TENANT and their wording is not the operator\'s:',
        '  '.implode(', ', $notTemplated),
        'Either resolve the body through App\\Support\\DocumentText::for() with the existing lang key',
        'as its floor, or add the class to TENANT_WORDING_EXEMPT with the reason its wording is not',
        'a phrasing decision. A shipped sentence is a default, not a policy.',
    ]));
});

it('keeps every exemption honest', function () {
    // A stale waiver is worse than none: it reports coverage the gate does not have. Same rule the
    // deletion-policy and screen-guide registers apply to theirs.
    $existing = collect(File::allFiles(app_path('Notifications')))
        ->map(fn ($f) => $f->getFilenameWithoutExtension())
        ->all();

    foreach (array_keys(TENANT_WORDING_EXEMPT) as $name) {
        expect(in_array($name, $existing, true))->toBeTrue("{$name} is exempt and no longer exists.");
    }

    foreach (TENANT_WORDING_EXEMPT as $name => $reason) {
        // A reason that does not NAME something is not reviewable — "not needed" reads as considered
        // and says nothing. Ask for a real sentence.
        expect(str_word_count($reason))->toBeGreaterThan(8, "{$name}'s exemption reason is too thin to review.");
    }
});

it('registers a floor for every key, so nothing ships blank', function () {
    // Every templated notice replaces a sentence that already existed, so each key must fall back
    // to it. A key with no floor would send an EMPTY mail line on an install that has written
    // nothing — the deployability rule slice 1 set, checked rather than trusted.
    foreach (DocumentText::KEYS as $key => $spec) {
        if (! str_starts_with($key, 'dunning.') && ! str_starts_with($key, 'receipt.') && ! str_starts_with($key, 'lease.')) {
            continue;   // the invoice BLOCKS are allowed a null floor; they render nothing at all
        }

        // A block may stand in for ANOTHER block instead of for a lang key — the final demand has no
        // historical wording to inherit, and giving it the reminder's lang key would make an
        // operator's own customised reminder revert to system wording at the sharpest moment. The
        // property this test defends ("nothing ships blank") still has to hold, so the target is
        // followed and must itself have a real floor.
        $fallback = DocumentText::FALLS_BACK_TO[$key] ?? null;

        if ($fallback !== null && $spec['floor'] === null) {
            // `toHaveKey($k, $v)` compares the VALUE against the second argument — it takes no
            // message — so the existence check is written as a plain boolean.
            expect(array_key_exists($fallback, DocumentText::KEYS))->toBeTrue("{$key} falls back to a block that is not registered.");
            expect(DocumentText::KEYS[$fallback]['floor'])->not->toBeNull("{$key} falls back to {$fallback}, which has no floor either — together they would send an empty line.");
            expect(Lang::has(DocumentText::KEYS[$fallback]['floor']))->toBeTrue("{$key}'s eventual floor names a translation key that does not exist.");

            continue;
        }

        expect($spec['floor'])->not->toBeNull("{$key} would send an empty line on a fresh install.");
        expect(Lang::has($spec['floor']))->toBeTrue("{$key}'s floor names a translation key that does not exist.");
    }
});

it('gives every registered block a picker label, in both languages', function () {
    // A key the resolver accepts and the PICKER cannot name is a block the operator can never
    // write. All five keys added for the messages shipped exactly that way: the dropdown on
    // `/admin/document-templates` renders its label from
    // `admin.document_templates_screen.blocks.{key}`, and with the entry missing it shows the raw
    // translation key — on the one screen whose whole purpose is choosing a block.
    //
    // `fallback: false` on BOTH, because `Lang::has($key, 'ar')` falls back to English and would
    // pass for a key that exists only in EN — the parity trap CLAUDE.md names.
    foreach (DocumentText::KEY_NAMES as $key) {
        $label = 'admin.document_templates_screen.blocks.'.str_replace('.', '_', $key);

        expect(Lang::has($label, 'en', false))->toBeTrue("{$key} has no English label; the picker would show its raw key.");
        expect(Lang::has($label, 'ar', false))->toBeTrue("{$key} has no Arabic label; the picker would show English to an Arabic operator.");
    }
});
