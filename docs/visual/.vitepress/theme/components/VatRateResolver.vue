<script setup lang="ts">
import { computed, ref } from 'vue'
import { useLocale } from '../useLocale'

/**
 * Why a back-dated invoice does not bill today's VAT rate.
 *
 * **A rate is a dated rung on a ladder, never a column.** `App\Support\Vat::rateForType($code, $on)`
 * resolves for the DOCUMENT's date, which is what lets a rise be entered months in advance and what
 * keeps a back-dated invoice on the rate that was in force when it was raised. Nothing rewrites
 * history: only ORIGINATION reads the ladder.
 *
 * The resolution is one rule — the latest rung whose effective date is on or before the document's
 * date — so mirroring it here cannot drift into a second opinion. The rungs below are ILLUSTRATIVE
 * and editable; the operator's real ladder lives in `/admin/tax-codes` and is their accountant's to
 * maintain, which is exactly the point being made.
 */
const { t, pct } = useLocale()

const copy = {
  title: { en: 'Rate ladder', ar: 'سُلَّم النسب' },
  from: { en: 'In force from', ar: 'سارية من' },
  rate: { en: 'Rate', ar: 'النسبة' },
  add: { en: 'Add a rung', ar: 'أضف درجة' },
  remove: { en: 'Remove', ar: 'حذف' },
  docDate: { en: 'Document date', ar: 'تاريخ المستند' },
  resolved: { en: 'Rate this document bills at', ar: 'النسبة التي يُفوتر بها هذا المستند' },
  none: {
    en: 'No rung is in force on that date — an unclassified code falls to the floor rate instead.',
    ar: 'لا توجد درجة سارية في هذا التاريخ — والكود غير المصنَّف يقع على النسبة الأدنى.',
  },
  because: { en: 'because it is the latest rung on or before', ar: 'لأنها آخر درجة في أو قبل' },
  future: {
    en: 'Rungs after the document date are ignored — which is what lets a rise be entered in advance.',
    ar: 'الدرجات اللاحقة لتاريخ المستند تُتجاهل — وهذا ما يتيح إدخال الزيادة مقدمًا.',
  },
  source: {
    en: 'Illustrates App\\Support\\Vat::rateForType(). The real ladder lives at /admin/tax-codes.',
    ar: 'يوضّح App\\Support\\Vat::rateForType(). والسُّلَّم الحقيقي في /admin/tax-codes.',
  },
}

const rungs = ref([
  { from: '2017-07-01', rate: 14 },
  { from: '2026-09-01', rate: 20 },
])

const docDate = ref('2026-08-15')

function addRung() {
  rungs.value.push({ from: docDate.value, rate: 14 })
}

function removeRung(index: number) {
  rungs.value.splice(index, 1)
}

/** The latest rung whose effective date is on or before the document's date. */
const resolved = computed(() => {
  const on = docDate.value
  const eligible = rungs.value
    .filter((r) => r.from && r.from <= on)
    .sort((a, b) => (a.from < b.from ? -1 : 1))

  return eligible.length ? eligible[eligible.length - 1] : null
})

const ignored = computed(() => rungs.value.filter((r) => r.from > docDate.value).length)
</script>

<template>
  <div class="calc">
    <div class="vat-cap">{{ t(copy.title) }}</div>

    <div class="vat-rungs">
      <div v-for="(rung, i) in rungs" :key="i" class="vat-rung" :class="{ on: resolved === rung }">
        <label>
          <span>{{ t(copy.from) }}</span>
          <input v-model="rung.from" type="date" />
        </label>
        <label>
          <span>{{ t(copy.rate) }} (%)</span>
          <input v-model.number="rung.rate" type="number" min="0" max="100" step="0.5" />
        </label>
        <button type="button" class="vat-x" :title="t(copy.remove)" @click="removeRung(i)">×</button>
      </div>
    </div>

    <button type="button" class="vat-add" @click="addRung">+ {{ t(copy.add) }}</button>

    <div class="calc-out">
      <label class="vat-doc">
        <span>{{ t(copy.docDate) }}</span>
        <input v-model="docDate" type="date" />
      </label>

      <div class="calc-num">
        <span class="calc-cap">{{ t(copy.resolved) }}</span>
        <b v-if="resolved">{{ pct(resolved.rate) }}%</b>
        <b v-else class="vat-none">—</b>
      </div>

      <p class="calc-why">
        <template v-if="resolved">
          {{ t(copy.because) }} {{ docDate }} ({{ resolved.from }}).
          <template v-if="ignored"> {{ t(copy.future) }}</template>
        </template>
        <template v-else>{{ t(copy.none) }}</template>
      </p>
    </div>

    <p class="calc-src">{{ t(copy.source) }}</p>
  </div>
</template>

<style scoped>
.calc {
  margin: 18px 0; padding: 16px 18px; border-radius: 10px;
  background: var(--paper); border: 1px solid var(--hair);
}
.vat-cap {
  font-family: var(--vp-font-family-mono); font-size: 10.5px; letter-spacing: 0.08em;
  text-transform: uppercase; color: var(--taupe); margin-bottom: 9px;
}
.vat-rungs { display: flex; flex-direction: column; gap: 8px; }
.vat-rung {
  display: flex; flex-wrap: wrap; align-items: end; gap: 10px;
  padding: 8px 11px; border-radius: 8px; border: 1px solid var(--hair); background: var(--vp-c-bg-elv);
}
/* The rung actually in force is the whole answer, so it is marked rather than merely listed. */
.vat-rung.on { border-color: var(--teal); background: var(--hl-bg); }
.vat-rung label { display: flex; flex-direction: column; gap: 3px; }
.vat-rung span { font-size: 0.74rem; color: var(--taupe-dk); }
.vat-rung input {
  padding: 5px 8px; border: 1px solid var(--vp-c-border); border-radius: 6px;
  background: var(--vp-c-bg); color: var(--vp-c-text-1);
  font-family: var(--vp-font-family-mono); font-size: 0.83rem;
}
.vat-x {
  font: inherit; font-size: 1.1rem; line-height: 1; cursor: pointer; border: 0;
  background: transparent; color: var(--taupe); padding: 4px 6px; margin-inline-start: auto;
}
.vat-x:hover { color: var(--red); }
.vat-add {
  font: inherit; font-size: 0.8rem; cursor: pointer; margin-top: 9px;
  background: transparent; border: 1px dashed var(--hair); border-radius: 7px;
  padding: 5px 12px; color: var(--taupe-dk);
}
.vat-add:hover { border-color: var(--teal); color: var(--teal-deep); }

.calc-out { margin-top: 15px; padding-top: 13px; border-top: 1px dashed var(--hair); }
.vat-doc { display: flex; flex-direction: column; gap: 4px; margin-bottom: 12px; max-width: 12rem; }
.vat-doc span { font-size: 0.78rem; color: var(--taupe-dk); }
.vat-doc input {
  padding: 7px 10px; border: 1px solid var(--vp-c-border); border-radius: 7px;
  background: var(--vp-c-bg-elv); color: var(--vp-c-text-1);
  font-family: var(--vp-font-family-mono); font-size: 0.88rem;
}
.calc-cap {
  display: block; font-family: var(--vp-font-family-mono); font-size: 10.5px;
  letter-spacing: 0.08em; text-transform: uppercase; color: var(--taupe);
}
.calc-num b { font-family: var(--serif); font-size: 1.5rem; color: var(--teal-deep); }
.calc-num b.vat-none { color: var(--taupe); }
.calc-why { margin: 8px 0 0; font-size: 0.85rem; color: var(--taupe-dk); line-height: 1.5; }
.calc-src { margin: 12px 0 0; font-size: 0.76rem; color: var(--taupe); font-style: italic; }
</style>
