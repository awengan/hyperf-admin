import { computed, defineComponent } from 'vue'
import { NDatePicker } from 'naive-ui'
import { fieldEmits, fieldProps } from '../lib/field.js'
import {
  formatForType,
  isRangeType,
  naiveDateType,
  toDateDisabled,
  toDefaultTime,
  toShortcuts,
} from '../lib/format.js'

/**
 * `date` / `datetime` — date picker.
 *
 * Values stay strings, exactly like Element UI's `value-format`: the picker is
 * driven through `formatted-value` + `value-format`, so what reaches PHP is
 * `2024-05-01` or `['2024-05-01', '2024-05-31']`.
 */
export default defineComponent({
  name: 'EaFieldDate',
  components: { NDatePicker },
  props: fieldProps,
  emits: fieldEmits,
  setup(props, { emit }) {
    const dateType = computed(() => props.field.date_type || 'date')

    const pickerType = computed(() => naiveDateType(dateType.value))
    const format = computed(() => formatForType(dateType.value))
    const shortcuts = computed(() => toShortcuts(props.field.shortcuts, dateType.value))
    const isDateDisabled = computed(() => toDateDisabled(props.field, dateType.value))
    const defaultTime = computed(() => toDefaultTime(props.field, dateType.value))

    const formattedValue = computed(() => {
      const current = props.modelValue

      if (current === undefined || current === null || current === '') {
        return null
      }

      if (isRangeType(dateType.value)) {
        return Array.isArray(current) && current.length === 2 ? [String(current[0]), String(current[1])] : null
      }

      return String(current)
    })

    function onUpdate(value) {
      emit('update:modelValue', value)
    }

    return {
      pickerType,
      format,
      shortcuts,
      isDateDisabled,
      defaultTime,
      formattedValue,
      onUpdate,
      range() {
        return isRangeType(dateType.value)
      },
    }
  },
  template: `
    <n-date-picker
      :type="pickerType"
      :formatted-value="formattedValue"
      :value-format="format"
      :format="format"
      :placeholder="range() ? field.start_placeholder : field.placeholder"
      :start-placeholder="field.start_placeholder"
      :end-placeholder="field.end_placeholder"
      :separator="field.range_separator"
      :clearable="field.clearable !== false"
      :disabled="!!field.disabled"
      :input-readonly="field.editable === false || !!field.readonly"
      :size="field.size || 'medium'"
      :shortcuts="shortcuts"
      :is-date-disabled="isDateDisabled"
      :default-time="defaultTime"
      class="ea-field"
      @update:formatted-value="onUpdate"
    />
  `,
})
