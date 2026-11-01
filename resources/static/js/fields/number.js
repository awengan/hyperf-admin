import { defineComponent } from 'vue'
import { NInputNumber } from 'naive-ui'
import { fieldEmits, fieldProps, useModelValue } from '../lib/field.js'

/**
 * `number` — numeric input with optional steppers.
 */
export default defineComponent({
  name: 'EaFieldNumber',
  components: { NInputNumber },
  props: fieldProps,
  emits: fieldEmits,
  setup(props, { emit }) {
    return {
      value: useModelValue(props, emit),
      precision() {
        const precision = props.field.precision

        return typeof precision === 'number' && precision >= 0 ? precision : undefined
      },
    }
  },
  template: `
    <n-input-number
      v-model:value="value"
      :min="field.min"
      :max="field.max"
      :step="field.step"
      :precision="precision()"
      :show-button="field.show_button !== false"
      :placeholder="field.placeholder"
      :disabled="!!field.disabled"
      :step-strictly="!!field.step_strictly"
    />
  `,
})
