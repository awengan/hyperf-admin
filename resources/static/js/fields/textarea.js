import { defineComponent } from 'vue'
import { NInput } from 'naive-ui'
import { fieldEmits, fieldProps, useModelValue } from '../lib/field.js'

/**
 * `textarea` — multi line input with Element UI's autosize behaviour.
 */
export default defineComponent({
  name: 'EaFieldTextarea',
  components: { NInput },
  props: fieldProps,
  emits: fieldEmits,
  setup(props, { emit }) {
    return {
      value: useModelValue(props, emit),
      autosize() {
        return {
          minRows: Number(props.field.min_rows) || 2,
          maxRows: Number(props.field.max_rows) || 4,
        }
      },
    }
  },
  template: `
    <n-input
      v-model:value="value"
      type="textarea"
      :autosize="autosize()"
      :placeholder="field.placeholder"
      :disabled="!!field.disabled"
      :clearable="field.clearable !== false"
    />
  `,
})
