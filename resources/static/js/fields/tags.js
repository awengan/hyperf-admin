/** 标签组：值是字符串数组。 */
import { NDynamicTags } from 'naive-ui'

export default {
  name: 'EaTags',
  components: { NDynamicTags },
  props: {
    modelValue: { type: [Array, String, null], default: () => [] },
    field: { type: Object, required: true },
    disabled: { type: Boolean, default: false },
  },
  emits: ['update:modelValue'],
  setup(props, { emit }) {
    /** 兼容后台存成逗号串的老数据 */
    function value() {
      const raw = props.modelValue

      if (Array.isArray(raw)) {
        return raw.filter((item) => item !== '')
      }

      return raw ? String(raw).split(',').filter(Boolean) : []
    }

    return { value, update: (next) => emit('update:modelValue', next) }
  },
  template: `
    <n-dynamic-tags :value="value()" :disabled="disabled" @update:value="update" />
  `,
}