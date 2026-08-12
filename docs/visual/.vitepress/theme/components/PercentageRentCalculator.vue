<script setup lang="ts">
import { computed, ref } from 'vue'
import { useLocale } from '../useLocale'

/**
 * Natural vs artificial breakpoint, worked through with the reader's own numbers.
 *
 * **This mirrors a rule; it is not the rule.** The authority is
 * `App\Services\PercentageRentService` and the lease's own terms — this exists because the
 * difference between the two bases is the single thing operators most reliably get backwards, and
 * a paragraph explaining it has never worked as well as changing a number and watching the answer
 * move. Both formulas are one line each, which is exactly why mirroring them here is safe: there is
 * no room for this copy to drift into a second opinion.
 *
 *   NATURAL     — the tenant pays the GREATER of base rent or (sales × rate). Billed: the amount by
 *                 which (sales × rate) beats base rent, and nothing when it does not.
 *   ARTIFICIAL  — the tenant pays base rent AND, on top, (sales − threshold) × rate.
 */
const { t, money, pct } = useLocale()

const copy = {
  basis: { en: 'Breakpoint', ar: 'أساس الاحتساب' },
  natural: { en: 'Natural', ar: 'طبيعي' },
  artificial: { en: 'Artificial', ar: 'مصطنع' },
  sales: { en: 'Declared sales', ar: 'المبيعات المُقرّة' },
  baseRent: { en: 'Base rent for the period', ar: 'الإيجار الأساسي للفترة' },
  threshold: { en: 'Agreed threshold', ar: 'الحد المتفق عليه' },
  rate: { en: 'Percentage rate', ar: 'نسبة الاحتساب' },
  result: { en: 'Percentage rent billed', ar: 'الإيجار النسبي المُفوتر' },
  nothing: { en: 'Nothing is billed this period.', ar: 'لا يُفوتر شيء هذه الفترة.' },
  naturalWhy: {
    en: 'sales × rate does not beat base rent, so the tenant pays base rent only.',
    ar: 'مبيعات × نسبة لا تتجاوز الإيجار الأساسي، فيدفع المستأجر الإيجار الأساسي فقط.',
  },
  artificialWhy: {
    en: 'sales are at or below the threshold, so nothing is owed on top of base rent.',
    ar: 'المبيعات عند الحد أو دونه، فلا شيء مستحق فوق الإيجار الأساسي.',
  },
  naturalNote: {
    en: 'The tenant pays the greater of base rent or sales × rate — billed here is only the excess.',
    ar: 'يدفع المستأجر الأكبر من الإيجار الأساسي أو (مبيعات × نسبة) — والمفوتر هنا هو الفرق فقط.',
  },
  artificialNote: {
    en: 'Base rent is paid anyway; this is the share of sales above the threshold, on top of it.',
    ar: 'الإيجار الأساسي مستحق في كل الأحوال؛ وهذا نصيب المبيعات فوق الحد، إضافةً إليه.',
  },
  source: {
    en: 'Illustrates App\\Services\\PercentageRentService. The lease terms are the authority.',
    ar: 'يوضّح App\\Services\\PercentageRentService. وشروط العقد هي المرجع.',
  },
}

const basis = ref<'natural' | 'artificial'>('natural')
const sales = ref(900000)
const baseRent = ref(60000)
const threshold = ref(700000)
const rate = ref(7)

const billed = computed(() => {
  const r = (Number(rate.value) || 0) / 100
  const s = Number(sales.value) || 0

  if (basis.value === 'natural') {
    return Math.max(0, s * r - (Number(baseRent.value) || 0))
  }

  return Math.max(0, (s - (Number(threshold.value) || 0)) * r)
})

const why = computed(() =>
  basis.value === 'natural' ? t(copy.naturalWhy) : t(copy.artificialWhy),
)
</script>

<template>
  <div class="calc">
    <div class="calc-row">
      <label>{{ t(copy.basis) }}</label>
      <div class="calc-toggle">
        <button type="button" :class="{ on: basis === 'natural' }" @click="basis = 'natural'">
          {{ t(copy.natural) }}
        </button>
        <button type="button" :class="{ on: basis === 'artificial' }" @click="basis = 'artificial'">
          {{ t(copy.artificial) }}
        </button>
      </div>
    </div>

    <div class="calc-grid">
      <label>
        <span>{{ t(copy.sales) }}</span>
        <input v-model.number="sales" type="number" min="0" step="1000" />
      </label>
      <label v-if="basis === 'natural'">
        <span>{{ t(copy.baseRent) }}</span>
        <input v-model.number="baseRent" type="number" min="0" step="1000" />
      </label>
      <label v-else>
        <span>{{ t(copy.threshold) }}</span>
        <input v-model.number="threshold" type="number" min="0" step="1000" />
      </label>
      <label>
        <span>{{ t(copy.rate) }} (%)</span>
        <input v-model.number="rate" type="number" min="0" max="100" step="0.5" />
      </label>
    </div>

    <div class="calc-out">
      <div class="calc-num">
        <span class="calc-cap">{{ t(copy.result) }}</span>
        <b>EGP {{ money(billed) }}</b>
      </div>
      <p class="calc-why">
        <template v-if="billed <= 0">{{ t(copy.nothing) }} {{ why }}</template>
        <template v-else-if="basis === 'natural'">
          {{ money(sales) }} × {{ pct(rate) }}% = {{ money((sales * rate) / 100) }},
          − {{ money(baseRent) }}. {{ t(copy.naturalNote) }}
        </template>
        <template v-else>
          ({{ money(sales) }} − {{ money(threshold) }}) × {{ pct(rate) }}%.
          {{ t(copy.artificialNote) }}
        </template>
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
.calc-row { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-bottom: 14px; }
.calc-row > label { font-size: 0.82rem; color: var(--taupe-dk); }
.calc-toggle { display: inline-flex; border: 1px solid var(--hair); border-radius: 8px; overflow: hidden; }
.calc-toggle button {
  font: inherit; font-size: 0.85rem; padding: 5px 14px; cursor: pointer;
  background: transparent; color: var(--vp-c-text-1); border: 0;
}
.calc-toggle button.on { background: var(--teal-bg); color: var(--teal-deep); font-weight: 600; }

.calc-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr)); gap: 12px; }
.calc-grid label { display: flex; flex-direction: column; gap: 4px; }
.calc-grid span { font-size: 0.78rem; color: var(--taupe-dk); }
.calc-grid input {
  padding: 7px 10px; border: 1px solid var(--vp-c-border); border-radius: 7px;
  background: var(--vp-c-bg-elv); color: var(--vp-c-text-1);
  font-family: var(--vp-font-family-mono); font-size: 0.88rem; width: 100%;
}

.calc-out { margin-top: 15px; padding-top: 13px; border-top: 1px dashed var(--hair); }
.calc-cap {
  display: block; font-family: var(--vp-font-family-mono); font-size: 10.5px;
  letter-spacing: 0.08em; text-transform: uppercase; color: var(--taupe);
}
.calc-num b { font-family: var(--serif); font-size: 1.5rem; color: var(--teal-deep); }
.calc-why { margin: 8px 0 0; font-size: 0.85rem; color: var(--taupe-dk); line-height: 1.5; }
.calc-src { margin: 12px 0 0; font-size: 0.76rem; color: var(--taupe); font-style: italic; }
</style>
