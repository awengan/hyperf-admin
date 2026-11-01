import { defineComponent } from 'vue'
import { NInput } from 'naive-ui'
import { fieldEmits, fieldProps, useModelValue } from '../lib/field.js'

/**
 * `password` — masked input with an optional reveal toggle.
 */
export default defineComponent({
  name: 'EaFieldPassword',
  components: { NInput },
  props: fieldProps,
  emits: fieldEmits,
  setup(props, { emit }) {
    return {
      value: useModelValue(props, emit),
      showPasswordOn() {
        const mode = props.field.show_password_on

        return mode === false || mode === 'none' ? undefined : mode === 'mousedown' ? 'mousedown' : 'click'
      },
    }
  },
  template: `
    <n-input
      v-model:value="value"
      type="password"
      :show-password-on="showPasswordOn()"
      :placeholder="field.placeholder"
      :disabled="!!field.disabled"
      :readonly="!!field.readonly"
      :clearable="field.clearable !== false"
    />
  `,
})
