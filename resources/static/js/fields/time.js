import { computed, defineComponent } from 'vue'
import { NSelect, NTimePicker } from 'naive-ui'
import { fieldEmits, fieldProps } from '../lib/field.js'
import { timeSelectOptions, toHourDisabled } from '../lib/format.js'

const TIME_FORMAT = 'HH:mm:ss'

/**
 * `time` — either a fixed interval list (`el-time-select`) or a free picker
 * (`el-time-picker`).
 *
 * Naive UI has no time *range* picker, so `is_range` renders two pickers whose
 * values are combined into a `[start, end]` pair.
 */
export default defineComponent({
  name: 'EaFieldTime',
  components: { NSelect, NTimePicker },
  props: fieldProps,
  emits: fieldEmits,
  setup(props, { emit }) {
    const isSelect = computed(() => props.field.time_type !== 'picker')
    const isRange = computed(() => !!props.field.is_range)

    const selectOptions = computed(() =>
      timeSelectOptions(props.field.start, props.field.end, props.field.step)
    )

    const isHourDisabled = computed(() => toHourDisabled(props.field.selectableRange))

    const value = computed({
      get: () => props.modelValue ?? null,
      set: (next) => emit('update:modelValue', next),
    })

    const rangeValue = computed(() => {
      const current = Array.isArray(props.modelValue) ? props.modelValue : []

      return [typeof current[0] === 'string' ? current[0] : null, typeof current[1] === 'string' ? current[1] : null]
    })

    function setRange(index, next) {
      const current = [...rangeValue.value]
      current[index] = next ?? null
      emit('update:modelValue', current)
    }

    return { isSelect, isRange, selectOptions, isHourDisabled, value, rangeValue, setRange, TIME_FORMAT }
  },
  template: `
    <n-select
      v-if="isSelect"
      v-model:value="value"
      :options="selectOptions"
      :placeholder="field.placeholder"
      :disabled="!!field.disabled"
      :clearable="field.clearable !== false"
      :size="field.size || 'medium'"
      class="ea-field"
    />

    <div v-else-if="isRange" class="ea-time-range">
      <n-time-picker
        :formatted-value="rangeValue[0]"
        :value-format="TIME_FORMAT"
        :format="TIME_FORMAT"
        :placeholder="field.start_placeholder"
        :is-hour-disabled="isHourDisabled"
        :disabled="!!field.disabled"
        :clearable="field.clearable !== false"
        :size="field.size || 'medium'"
        @update:formatted-value="(next) => setRange(0, next)"
      />
      <span class="ea-time-range__separator">-</span>
      <n-time-picker
        :formatted-value="rangeValue[1]"
        :value-format="TIME_FORMAT"
        :format="TIME_FORMAT"
        :placeholder="field.end_placeholder"
        :is-hour-disabled="isHourDisabled"
        :disabled="!!field.disabled"
        :clearable="field.clearable !== false"
        :size="field.size || 'medium'"
        @update:formatted-value="(next) => setRange(1, next)"
      />
    </div>

    <n-time-picker
      v-else
      :formatted-value="value"
      :value-format="TIME_FORMAT"
      :format="TIME_FORMAT"
      :placeholder="field.placeholder"
      :is-hour-disabled="isHourDisabled"
      :disabled="!!field.disabled"
      :clearable="field.clearable !== false"
      :size="field.size || 'medium'"
      class="ea-field"
      @update:formatted-value="(next) => (value = next)"
    />
  `,
})
