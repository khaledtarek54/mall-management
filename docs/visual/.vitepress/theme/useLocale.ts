import { useData } from 'vitepress'
import { computed } from 'vue'

/**
 * Which language the reader is in, and a tiny lookup for component strings.
 *
 * The handbook is bilingual, so a component that hard-codes English labels renders an English
 * widget in the middle of an Arabic page — which reads as broken rather than untranslated. Every
 * component here takes its strings through this, so adding a language is one more key per string
 * rather than a second copy of the component.
 */
export function useLocale() {
  const { lang } = useData()

  const isArabic = computed(() => lang.value.startsWith('ar'))

  /** Pick the reader's side of a `{ en, ar }` pair. */
  function t<T>(pair: { en: T; ar: T }): T {
    return isArabic.value ? pair.ar : pair.en
  }

  /**
   * Format money the way the panel does: Western digits, two decimals, EGP.
   *
   * `ar-EG` would emit Arabic-Indic digits, which the app deliberately does not use — the
   * accountants read Western numerals and the invoices print them, so a handbook that showed
   * ٤٢٠٠٠ beside a screen showing 42,000 would be teaching the wrong thing.
   */
  function money(value: number): string {
    if (!isFinite(value)) return '—'
    return new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value)
  }

  function pct(value: number): string {
    if (!isFinite(value)) return '—'
    return new Intl.NumberFormat('en-US', { maximumFractionDigits: 2 }).format(value)
  }

  return { isArabic, t, money, pct }
}
