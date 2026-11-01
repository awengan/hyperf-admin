/** 评分：值是数字。 */
import { NRate } from 'naive-ui'

export default {
  name: 'EaRate',
  components: { NRate },
  props: {
    modelValue: { type: [Number, String, null], default: 0 },
    field: { type: Object, required: true },
    disabled: { type: Boolean, default: false },
  },
  emits: ['update:modelValue'],
  setup(props) {
    return {
      count: () => Number(props.field.count || 5),
      allowHalf: () => props.field.allowHalf === true,
    }
  },
  template: `
    <n-rate
      :value="Number(modelValue) || 0"
      :count="count()"
      :allow-half="allowHalf()"
      :disabled="disabled"
      @update:value="$emit('update:modelValue', $event)"
    />
  `,
}