<?php

namespace App\Models;

use App\Support\ActivityLogging;
use App\Support\Attributes\DeletionAllowed;
use App\Support\Attributes\PropertyOwned;
use App\Support\DocumentText;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One block of standing wording on a tenant-facing document (EG-15, finding S-6).
 *
 * `key` names the block ({@see DocumentText::KEYS}); `asset_id` null is the portfolio
 * default and a row with an asset overrides it for that mall. Both languages live on the one row,
 * so an install cannot acquire an English footer with no Arabic one and no screen showing the gap.
 *
 * **Deletable, and classified `DeletionAllowed` rather than `DeletableWhenUnused`.** A template
 * settles nothing and nothing points at it — documents resolve a block by KEY at render time — so
 * deleting one falls them back to the house default or the built-in text. That is a working
 * outcome, which is the test this project applies before refusing a deletion. Switching the row
 * off does the same thing while keeping the wording on file, and is the gentler move.
 */
#[DeletionAllowed(reason: 'a wording block is referenced by nothing — documents resolve it by KEY at render time, so deleting one falls them back to the house default or the built-in text, which is a working outcome rather than a broken one. `DeletableWhenUnused` was wrong here: with no relations to block on it would have been a refusal with nothing to refuse.')]
// A null `asset_id` is the PORTFOLIO DEFAULT and must be visible from every mall — the same third
// case the five money models have. Scoping this strictly would hide the house default from every
// screen, which is the row an operator writes first.
#[PropertyOwned(portfolioRowsWhenNull: true)]
class DocumentTemplate extends Model
{
    use LogsActivity;

    protected $fillable = [
        'key',
        'asset_id',
        'body_en',
        'body_ar',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        // ONE row per block per property — including the HOUSE row, which neither layer above this
        // one could see.
        //
        // Measured 2026-09-04 at HEAD (83624504). The form's `Rule::unique` scoped itself with the
        // Radio's raw state, and `PropertyField::scope()`'s blank is the STRING `''`, not null — so
        // it compiled to `where "key" = ? and ("asset_id" = ?)` bound to `""`, which can never match
        // a NULL row. And the index underneath is no help: `SHOW CREATE TABLE` gives
        // `UNIQUE KEY (key, asset_id)` over a nullable column, and MySQL — like SQLite — permits
        // unlimited duplicates where one side is NULL. So a second house footer saved cleanly and
        // `DocumentText::operatorText()` chose between the two by storage order, which this table's
        // own migration calls "a silent tie ... which is nobody's decision".
        //
        // Guarded on the model for the reason the `key` value set already is: an import, a seeder
        // or a crafted payload meets this, not the picker.
        static::saving(function (self $template): void {
            // Only on a write that CREATES or MOVES the pair. This shipped unenforced, so an
            // install may already carry two house rows — refusing every save would make both of
            // them uneditable and put the refusal's own escape ("switch one off") out of reach.
            // Same rule and same reason as the bank-account chart guard.
            if ($template->exists && ! $template->isDirty(['key', 'asset_id'])) {
                return;
            }

            $clash = static::query()
                ->where('key', $template->key)
                // Laravel compiles `where($column, null)` to `is null`; the empty string the form
                // was handing it did not, and that is the whole of this bug.
                ->where('asset_id', $template->asset_id)
                ->when($template->exists, fn ($query) => $query->whereKeyNot($template->getKey()))
                ->exists();

            if ($clash) {
                throw new \DomainException(__('admin.refusals.document_template_duplicate_block', [
                    'block' => __('admin.document_templates_screen.blocks.'.str_replace('.', '_', (string) $template->key)),
                ]));
            }
        });
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * This row's text in `$locale`, falling back to the other language.
     *
     * The fallback is deliberate and it is the lesser evil: an operator who has written only the
     * Arabic footer should see the Arabic footer on an English invoice rather than a blank space
     * where the payment terms belong. A missing sentence on a document about money is worse than
     * one in the wrong language, and the form asks for both.
     */
    public function bodyFor(string $locale): ?string
    {
        $own = $locale === 'ar' ? $this->body_ar : $this->body_en;
        $other = $locale === 'ar' ? $this->body_en : $this->body_ar;

        return filled($own) ? $own : (filled($other) ? $other : null);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return ActivityLogging::for($this, 'document_template');
    }
}
