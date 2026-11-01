/**
 * Shared plumbing for the form field controls.
 *
 * A control only renders the widget: the surrounding `n-form-item` (label,
 * validation path) is owned by `FormPage.vue`'s equivalent in `FormPage.js`.
 */

import { computed } from 'vue'

export const fieldProps = {
  /** Raw field payload produced by the PHP builder. */
  field: { type: Object, required: true },
  /** v-model value. */
  modelValue: { type: null, default: undefined },
}

export const fieldEmits = ['update:modelValue']

/**
 * Two-way binding helper: `computed` acting as the component's v-model.
 */
export function useModelValue(props, emit) {
  return computed({
    get: () => props.modelValue,
    set: (value) => emit('update:modelValue', value),
  })
}

/**
 * Ensure a colour string is usable in CSS (`409eff` -> `#409eff`).
 */
export function toColor(value, fallback) {
  const raw = String(value ?? '').trim()

  if (raw === '') {
    return fallback
  }

  return /^#|^rgb|^hsl|^[a-z]+$/i.test(raw) ? raw : '#' + raw
}

/**
 * `n-slider` marks used to render the Element UI `show-stops` ticks.
 */
export function toSliderMarks(field) {
  if (!field?.show_stops) {
    return undefined
  }

  const min = Number(field.min) || 0
  const max = Number(field.max) || 100
  const step = Number(field.step) || 1
  const marks = {}

  // Guard against pathological step values producing thousands of marks.
  const count = Math.floor((max - min) / step)

  if (step <= 0 || count < 0 || count > 100) {
    return undefined
  }

  for (let index = 0; index <= count; index += 1) {
    marks[min + index * step] = ''
  }

  return marks
}
