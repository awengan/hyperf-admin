import { computed, defineComponent } from 'vue'
import { NRadio, NRadioButton, NRadioGroup, NSpace } from 'naive-ui'
import { fieldEmits, fieldProps, useModelValue } from '../lib/field.js'
import { toSelectOptions } from '../lib/payload.js'

/**
 * `radio` — radio group; `field.button` switches to `n-radio-button`, and the
 * `border` list renders bordered cards (Naive UI has no bordered radio).
 */
export default defineComponent({
  name: 'EaFieldRadio',
  components: { NRadio, NRadioButton, NRadioGroup, NSpace },
  props: fieldProps,
  emits: fieldEmits,
  setup(props, { emit }) {
    return {
      value: useModelValue(props, emit),
      options: computed(() => toSelectOptions(props.field.options, props.field.disabled)),
      bordered() {
        const keys = Array.isArray(props.field.border) ? props.field.border : []

        return props.field.button ? true : keys.length > 0
      },
    }
  },
  template: `
    <n-radio-group
      v-model:value="value"
      :size="field.size || 'medium'"
      class="ea-field"
    >
      <n-space :class="{ 'ea-field--bordered': bordered() }" :size="8">
        <template v-if="field.button">
          <n-radio-button
            v-for="option in options"
            :key="String(option.value)"
            :value="option.value"
            :label="option.label"
            :disabled="option.disabled"
          />
        </template>
        <template v-else>
          <n-radio
            v-for="option in options"
            :key="String(option.value)"
            :value="option.value"
            :disabled="option.disabled"
          >
            {{ option.label }}
          </n-radio>
        </template>
      </n-space>
    </n-radio-group>
  `,
})
