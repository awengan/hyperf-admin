/** 颜色选择：值形如 `#1677ff`。 */
import { NColorPicker } from 'naive-ui'

export default {
  name: 'EaColor',
  components: { NColorPicker },
  props: {
    modelValue: { type: [String, null], default: '' },
    field: { type: Object, required: true },
    disabled: { type: Boolean, default: false },
  },
  emits: ['update:modelValue'],
  template: `
    <n-color-picker
      :value="modelValue || null"
      :disabled="disabled"
      :show-alpha="field.alpha === true"
      :modes="['hex', 'rgb']"
      @update:value="$emit('update:modelValue', $event || '')"
    />
  `,
}