import { defineComponent } from 'vue'
import { NSwitch } from 'naive-ui'
import { fieldEmits, fieldProps, toColor, useModelValue } from '../lib/field.js'

/**
 * `switch` — on/off toggle.
 *
 * Element UI's `active-color` / `inactive-color` become Naive UI's
 * `rail-style`, and the active/inactive texts become the two slots.
 */
export default defineComponent({
  name: 'EaFieldSwitch',
  components: { NSwitch },
  props: fieldProps,
  emits: fieldEmits,
  setup(props, { emit }) {
    return {
      value: useModelValue(props, emit),
      railStyle(info) {
        return {
          backgroundColor: info.checked
            ? toColor(props.field.active_color, '#18a058')
            : toColor(props.field.inactive_color, 'rgba(0, 0, 0, 0.45)'),
        }
      },
    }
  },
  template: `
    <n-switch
      v-model:value="value"
      :checked-value="field.active_value"
      :unchecked-value="field.inactive_value"
      :disabled="!!field.disabled"
      :rail-style="railStyle"
    >
      <template v-if="field.active_text" #checked>{{ field.active_text }}</template>
      <template v-if="field.inactive_text" #unchecked>{{ field.inactive_text }}</template>
    </n-switch>
  `,
})
