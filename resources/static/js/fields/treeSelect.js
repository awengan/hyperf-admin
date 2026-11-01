/**
 * 树形选择字段（`n-tree-select`）。
 *
 * 选项是**树形**结构：`[{ label, key, children }]`，
 * 和其它字段的扁平 `{key: label}` 不同。
 */
import { NTreeSelect } from 'naive-ui'

export default {
  name: 'EaTreeSelect',

  components: { NTreeSelect },

  props: {
    modelValue: { type: [String, Number, null], default: null },
    model: { type: Object, default: () => ({}) },
    field: { type: Object, required: true },
  },

  emits: ['update:modelValue'],

  setup(props, { emit }) {
    function update(value) {
      emit('update:modelValue', value)
    }

    return { update }
  },

  template: `
    <n-tree-select
      :value="modelValue"
      :options="field.options || []"
      :clearable="field.clearable !== false"
      :default-expand-all="field.default_expand_all !== false"
      :placeholder="'请选择' + (field.label || '')"
      key-field="key"
      label-field="label"
      children-field="children"
      @update:value="update"
    />
  `,
}
