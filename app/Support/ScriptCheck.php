<?php

namespace App\Support;

/**
 * Is a shopper-facing value written in the language its field is FOR?
 *
 * Reported by the tester: the tenant's shopper-facing name accepted English in the (AR) box and
 * Arabic in the (EN) box, so an Arabic-speaking shopper could open the visitor app and be shown a
 * name in a script they do not read — on the one field that exists precisely to avoid that.
 *
 * **It asks for PRESENCE, never for purity, and that distinction is the whole design.** A real
 * Egyptian mall's tenant list is full of legitimately mixed names: «زارا ZARA», «H&M مصر»,
 * «كارفور Carrefour». Requiring a field to be *only* Arabic would refuse most of the register and
 * be a worse bug than the one being fixed. Requiring it to contain *some* Arabic catches exactly
 * the reported mistake — the whole value typed in the wrong box — and nothing else.
 *
 * Digits, punctuation and spaces are neutral: they belong to both scripts and say nothing about
 * which language a name is in.
 *
 * This is a form-level check, not a model rule: an importer carrying a migrating operator's real
 * file must not be refused row by row over a naming convention.
 */
class ScriptCheck
{
    /** Does this value contain at least one Arabic letter? */
    public static function hasArabic(?string $value): bool
    {
        return filled($value) && preg_match('/\p{Arabic}/u', (string) $value) === 1;
    }

    /** Does this value contain at least one Latin letter? */
    public static function hasLatin(?string $value): bool
    {
        return filled($value) && preg_match('/\p{Latin}/u', (string) $value) === 1;
    }

    /**
     * Is this value written in NEITHER script — i.e. digits and punctuation only?
     *
     * Such a value carries no language at all, so neither check should fire on it: a store called
     * "700" is a name somebody chose, and refusing it would be this rule inventing a policy about
     * naming rather than catching a wrong box.
     */
    public static function carriesNoLetters(?string $value): bool
    {
        return filled($value) && ! self::hasArabic($value) && ! self::hasLatin($value);
    }
}
