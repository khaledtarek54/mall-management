<script setup lang="ts">
import { computed, ref } from 'vue'
import workflows from '../../data/workflows.json'
import { useLocale } from '../useLocale'

/**
 * A workflow's states, and what each one may move to — click a state to follow it.
 *
 * Drawn from the same `TRANSITIONS` matrices the SERVICES enforce, dumped by
 * `atriom:dump-handbook-data`. So it cannot show a transition the code refuses, nor miss one it
 * allows — which is the property the admin Workflows page was built for, and the reason neither
 * re-lists the flows by hand.
 *
 * A state with nothing after it is terminal, and that is the fact operators most often want: a
 * closed request cannot be re-opened, and no amount of clicking will find a path out of it.
 */
const props = defineProps<{ workflow: 'tenant_request' | 'work_order' | 'purchase_request' }>()

const { t } = useLocale()

const copy = {
  from: { en: 'From', ar: 'من' },
  goesTo: { en: 'may move to', ar: 'يمكن أن ينتقل إلى' },
  terminal: { en: 'End — nothing follows it', ar: 'نهاية — لا شيء بعدها' },
  pick: { en: 'Pick a state to see where it can go.', ar: 'اختر حالة لترى إلى أين يمكن أن تنتقل.' },
  states: { en: 'states', ar: 'حالة' },
  ends: { en: 'of them are ends', ar: 'منها نهايات' },
}

/** Statuses read better spaced than snake_cased, and the raw value stays visible on the chip. */
function label(state: string) {
  return state.replace(/_/g, ' ')
}

const matrix = computed<Record<string, string[]>>(() => (workflows as any)[props.workflow] ?? {})
const states = computed(() => Object.keys(matrix.value))
const terminals = computed(() => states.value.filter((s) => (matrix.value[s] ?? []).length === 0))

const selected = ref<string | null>(null)
const exits = computed(() => (selected.value ? matrix.value[selected.value] ?? [] : []))
</script>

<template>
  <div class="sm">
    <div class="sm-meta">
      {{ states.length }} {{ t(copy.states) }} · {{ terminals.length }} {{ t(copy.ends) }}
    </div>

    <div class="sm-states">
      <button
        v-for="state in states"
        :key="state"
        type="button"
        class="sm-chip"
        :class="{
          'is-on': selected === state,
          'is-end': (matrix[state] ?? []).length === 0,
          'is-next': selected !== null && exits.includes(state),
        }"
        @click="selected = selected === state ? null : state"
      >{{ label(state) }}</button>
    </div>

    <div class="sm-detail">
      <p v-if="!selected" class="sm-hint">{{ t(copy.pick) }}</p>
      <template v-else>
        <p v-if="!exits.length" class="sm-end">
          <b>{{ label(selected) }}</b> — {{ t(copy.terminal) }}
        </p>
        <p v-else>
          <b>{{ label(selected) }}</b> {{ t(copy.goesTo) }}
          <span v-for="next in exits" :key="next" class="sm-next">{{ label(next) }}</span>
        </p>
      </template>
    </div>
  </div>
</template>

<style scoped>
.sm { margin: 18px 0; }
.sm-meta { font-family: var(--vp-font-family-mono); font-size: 11.5px; color: var(--taupe); margin-bottom: 8px; }
.sm-states { display: flex; flex-wrap: wrap; gap: 7px; }
.sm-chip {
  font: inherit; font-size: 0.85rem; cursor: pointer;
  padding: 6px 13px; border-radius: 20px; border: 1.5px solid var(--hair);
  background: var(--paper); color: var(--vp-c-text-1); transition: background 0.12s, border-color 0.12s;
}
.sm-chip:hover { border-color: var(--teal); }
.sm-chip.is-on { background: var(--teal-bg); border-color: var(--teal); color: var(--teal-deep); font-weight: 600; }
.sm-chip.is-next { border-color: var(--teal); background: var(--hl-bg); }
/* A terminal state is the answer people most often come for, so it looks different at rest. */
.sm-chip.is-end { border-style: dashed; color: var(--taupe-dk); }

.sm-detail {
  margin-top: 12px; padding: 12px 15px; border-radius: 9px;
  background: var(--rule-bg); border: 1px solid var(--hl-border); font-size: 0.9rem;
}
.sm-detail p { margin: 0; }
.sm-hint { color: var(--taupe-dk); font-style: italic; }
.sm-end { color: var(--taupe-dk); }
.sm-next {
  display: inline-block; margin-inline-start: 6px; padding: 1px 9px; border-radius: 20px;
  background: var(--teal-bg); color: var(--teal-deep); font-size: 0.82rem;
}
</style>
