<script setup lang="ts">
import { computed, ref } from 'vue'
import sources from '../../data/gl-sources.json'
import { useLocale } from '../useLocale'

/**
 * Every document that reaches the general ledger, and the four things an operator asks about one.
 *
 * Fed by `atriom:dump-handbook-data`, which derives it from `LedgerPoster::JOURNALIZERS` — the same
 * single registry all four dispatch paths derive from. So this cannot list a source the system does
 * not post, nor miss one it does. `docs/modules/21-general-ledger.md` once claimed 12 posting
 * sources while the code posted 21, and a diagram is worse than a paragraph for that: a picture of
 * a system reads as authoritative in a way prose does not.
 *
 * It deliberately does NOT draw the debit/credit lines. Those are resolved per line at runtime
 * through the charge-code table, so any map of them here would be a guess rendered as a diagram.
 */
const { t } = useLocale()
const query = ref('')

const copy = {
  search: { en: 'Filter documents…', ar: 'ابحث في المستندات…' },
  document: { en: 'Document', ar: 'المستند' },
  dated: { en: 'Entry dated by', ar: 'تاريخ القيد من' },
  guard: { en: 'Closed-period guard', ar: 'حارس الفترة المقفلة' },
  deletable: { en: 'Deletion', ar: 'الحذف' },
  edits: { en: 'Edits to a posted entry', ar: 'تعديل قيد مُرحَّل' },
  never: { en: 'Never — correct it instead', ar: 'ممنوع — يُصحَّح بدلًا من ذلك' },
  whenUnused: { en: 'Only while unused', ar: 'فقط ما دام غير مستخدم' },
  allowed: { en: 'Allowed', ar: 'مسموح' },
  systemDated: { en: 'System-dated', ar: 'بتاريخ النظام' },
  none: { en: 'No documents match.', ar: 'لا مستندات مطابقة.' },
  count: { en: 'documents post to the ledger', ar: 'مستندًا تُرحَّل إلى الدفتر' },
  refused: { en: 'refused', ar: 'مرفوض' },
  derived: { en: 're-derived', ar: 'يُعاد اشتقاقه' },
  prospective: { en: 'future only', ar: 'للمستقبل فقط' },
  descriptive: { en: 'text only', ar: 'نص فقط' },
  neutral: { en: 'no effect', ar: 'بلا أثر' },
}

const rows = computed(() => {
  const q = query.value.trim().toLowerCase()
  const all = sources as any[]
  if (!q) return all
  return all.filter((r) => r.label.toLowerCase().includes(q) || r.journalizer.toLowerCase().includes(q))
})

function tierLabel(tier: string) {
  return tier === 'never' ? t(copy.never) : tier === 'when_unused' ? t(copy.whenUnused) : t(copy.allowed)
}

function impactLabel(key: string) {
  return t((copy as any)[key] ?? { en: key, ar: key })
}
</script>

<template>
  <div class="pex">
    <div class="pex-head">
      <input v-model="query" type="search" class="pex-search" :placeholder="t(copy.search)" />
      <span class="pex-count">{{ rows.length }} {{ t(copy.count) }}</span>
    </div>

    <p v-if="!rows.length" class="pex-empty">{{ t(copy.none) }}</p>

    <div v-else class="pex-scroll">
      <table class="pex-table">
        <thead>
          <tr>
            <th>{{ t(copy.document) }}</th>
            <th>{{ t(copy.dated) }}</th>
            <th>{{ t(copy.guard) }}</th>
            <th>{{ t(copy.edits) }}</th>
            <th>{{ t(copy.deletable) }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in rows" :key="row.model">
            <td>
              <b>{{ row.label }}</b>
              <small>{{ row.journalizer }}</small>
            </td>
            <td>
              <code v-if="row.date_column">{{ row.date_column }}</code>
              <span v-else>—</span>
            </td>
            <td>
              <span v-if="row.posting_date_guard" class="pex-yes">{{ row.posting_date_guard }}</span>
              <span v-else-if="row.posting_date_note" class="pex-sys" :title="row.posting_date_note">
                {{ t(copy.systemDated) }}
              </span>
              <span v-else>—</span>
            </td>
            <td>
              <span
                v-for="(n, key) in row.change_impact"
                :key="key"
                class="pex-chip"
                :class="'ci-' + key"
              >{{ n }} {{ impactLabel(String(key)) }}</span>
            </td>
            <td>
              <span
                class="pex-tier"
                :class="'tier-' + row.deletable.tier"
                :title="row.deletable.instead || ''"
              >{{ tierLabel(row.deletable.tier) }}</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<style scoped>
.pex { margin: 18px 0; }
.pex-head { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-bottom: 10px; }
.pex-search {
  flex: 1 1 14rem; padding: 7px 11px; border: 1px solid var(--vp-c-border);
  border-radius: 8px; background: var(--vp-c-bg-elv); color: var(--vp-c-text-1); font-size: 0.9rem;
}
.pex-count { font-family: var(--vp-font-family-mono); font-size: 12px; color: var(--taupe-dk); }
.pex-empty { color: var(--taupe-dk); font-style: italic; }

/* Wide table: scrolls inside its OWN box, so the page body never scrolls sideways. */
.pex-scroll { overflow-x: auto; border: 1px solid var(--hair); border-radius: 10px; background: var(--paper); }
.pex-table { width: 100%; border-collapse: collapse; font-size: 0.86rem; margin: 0; display: table; }
.pex-table th {
  font-family: var(--vp-font-family-mono); font-size: 10.5px; letter-spacing: 0.08em;
  text-transform: uppercase; color: var(--taupe); font-weight: 600; text-align: start;
  padding: 10px 12px; border-bottom: 1px solid var(--hair); white-space: nowrap;
}
.pex-table td { padding: 9px 12px; border-bottom: 1px solid var(--hair); vertical-align: top; }
.pex-table tr:last-child td { border-bottom: 0; }
.pex-table td b { display: block; font-weight: 600; }
.pex-table td small { color: var(--taupe); font-family: var(--vp-font-family-mono); font-size: 10.5px; }
.pex-table code { font-size: 11.5px; background: var(--code-chip); padding: 1px 5px; border-radius: 4px; }

.pex-yes { font-family: var(--vp-font-family-mono); font-size: 11px; color: var(--teal-deep); }
.pex-sys { font-size: 11.5px; color: var(--taupe-dk); border-bottom: 1px dotted var(--taupe); cursor: help; }

.pex-chip {
  display: inline-block; font-size: 10.5px; padding: 1px 7px; border-radius: 20px;
  margin-inline-end: 4px; margin-block-end: 3px; white-space: nowrap;
}
.ci-refused { background: var(--red-bg); color: var(--red); }
.ci-derived { background: var(--teal-bg); color: var(--teal-deep); }
.ci-prospective { background: var(--amber-bg); color: var(--amber); }
.ci-descriptive,
.ci-neutral { background: var(--grey-bg); color: var(--taupe-dk); }

.pex-tier { font-size: 11.5px; white-space: nowrap; }
.tier-never { color: var(--red); font-weight: 600; cursor: help; }
.tier-when_unused { color: var(--amber); cursor: help; }
.tier-allowed { color: var(--taupe-dk); }
</style>
