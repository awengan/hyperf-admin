import { defineComponent } from 'vue'
import { NInputNumber, NSlider } from 'naive-ui'
import { fieldEmits, fieldProps, toSliderMarks, useModelValue } from '../lib/field.js'

/**
 * `slider` — slider with an optional companion number input.
 *
 * `show_stops` becomes `marks` (Naive UI has no dedicated stop dots) and
 * `show_input` renders an `n-input-number` next to the rail.
 */
export default defineComponent({
  name: 'EaFieldSlider',
  components: { NInputNumber, NSlider },
  props: fieldProps,
  emits: fieldEmits,
  setup(props, { emit }) {
    return {
      value: useModelValue(props, emit),
      marks() {
        return toSliderMarks(props.field)
      },
      formatTooltip(value) {
        const divisor = Number(props.field.format_tooltip) || 1

        return divisor === 1 ? value : value / divisor
      },
      inputNumber() {
        // The companion input must be a scalar, so the first handle is used for
        // range sliders (matching Element UI, which has no range input box).
        const current = props.modelValue

        return Array.isArray(current) ? current[0] : current
      },
      onInputNumber(value) {
        const current = props.modelValue

        if (Array.isArray(current)) {
          emit('update:modelValue', [value, current[1]])
          return
        }

        emit('update:modelValue', value)
      },
    }
  },
  template: `
    <div class="ea-slider" :class="{ 'ea-slider--vertical': !!field.vertical }">
      <div class="ea-slider__range">
        <n-slider
          v-model:value="value"
          :min="field.min"
          :max="field.max"
          :step="field.step"
          :range="!!field.range"
          :vertical="!!field.vertical"
          :disabled="!!field.disabled"
          :marks="marks()"
          :show-tooltip="field.show_tooltip !== false"
          :format-tooltip="formatTooltip"
        />
      </div>
      <n-input-number
        v-if="field.show_input"
        :value="inputNumber()"
        :min="field.min"
        :max="field.max"
        :step="field.step"
        :disabled="!!field.disabled"
        style="width: 120px"
        @update:value="onInputNumber"
      />
    </div>
  `,
})
