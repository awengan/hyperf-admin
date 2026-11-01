import { defineComponent } from 'vue'
import { NInput } from 'naive-ui'
import { fieldEmits, fieldProps, useModelValue } from '../lib/field.js'

/**
 * `text` — single line input.
 */
export default defineComponent({
  name: 'EaFieldText',
  components: { NInput },
  props: fieldProps,
  emits: fieldEmits,
  setup(props, { emit }) {
    return { value: useModelValue(props, emit) }
  },
  template: `
    <n-input
      v-model:value="value"
      :placeholder="field.placeholder"
      :disabled="!!field.disabled"
      :readonly="!!field.readonly"
      :clearable="field.clearable !== false"
      :maxlength="field.maxlength || undefined"
    />
  `,
})
