import { computed, defineComponent } from 'vue'
import { NCheckbox, NCheckboxGroup, NSpace } from 'naive-ui'
import { fieldEmits, fieldProps } from '../lib/field.js'
import { toSelectOptions } from '../lib/payload.js'

/**
 * `checkbox` — checkbox group.
 *
 * Naive UI has no checkbox-button variant, so `field.button` renders the same
 * bordered cards as `field.border`. The `min` / `max` selection bounds are
 * enforced here.
 */
export default defineComponent({
  name: 'EaFieldCheckbox',
  components: { NCheckbox, NCheckboxGroup, NSpace },
  props: fieldProps,
  emits: fieldEmits,
  setup(props, { emit }) {
    const value = computed({
      get: () => (Array.isArray(props.modelValue) ? props.modelValue : []),
      set: (next) => {
        const min = Number(props.field.min) || 0
        const max = Number(props.field.max) || 0
        const size = Array.isArray(next) ? next.length : 0

        // Reject the change when it breaks a configured bound; the widget keeps
        // showing the previous selection.
        if (min > 0 && size < min) {
          return
        }

        if (max > 0 && size > max) {
          return
        }

        emit('update:modelValue', next)
      },
    })

    return {
      value,
      options: computed(() => toSelectOptions(props.field.options, props.field.disabled)),
      bordered() {
        const keys = Array.isArray(props.field.border) ? props.field.border : []

        return props.field.button ? true : keys.length > 0
      },
    }
  },
  template: `
    <n-checkbox-group v-model:value="value" class="ea-field">
      <n-space :class="{ 'ea-field--bordered': bordered() }" :size="8">
        <n-checkbox
          v-for="option in options"
          :key="String(option.value)"
          :value="option.value"
          :label="option.label"
          :disabled="option.disabled"
        />
      </n-space>
    </n-checkbox-group>
  `,
})
